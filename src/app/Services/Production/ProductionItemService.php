<?php

namespace App\Services\Production;

use App\Models\ProductionItem;
use App\Models\Menu;
use App\Models\WasteRecord;
use App\Exceptions\BusinessRuleException;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesOutletBrand;
use Illuminate\Support\Facades\DB;

class ProductionItemService extends BaseService
{
    use ResolvesOutletBrand;

    protected string $model = ProductionItem::class;

    protected array $relations = ['menu'];
    protected array $searchable = ['plate_color', 'status', 'outlet_id'];
    protected array $sortable = ['produced_at', 'created_at'];

    /**
     * Menutup piring sebagai waste dan mencatat `waste_records` adalah satu
     * aksi bisnis, jadi keduanya tinggal di satu transaksi di sini. Jalur
     * per-piring masih merangkainya dari controller — lihat catatan di
     * ProductionController::updateExpired().
     */
    public function __construct(
        protected WasteRecordService $wasteRecords,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | PRODUCE ITEM
    |--------------------------------------------------------------------------
    */
    public function produce(array $data)
    {   
        return DB::transaction(function () use ($data) {
 
            $menu = Menu::with('plateColor')->findOrFail($data['menuId']);

            // ✅ guard (lebih aman & jelas)
            if (!$menu->plateColor?->id) {
                throw new \Exception("Menu belum memiliki plate color yang valid");
            }

            $this->assertMenuBelongsToOutletBrand($menu, $data['outletId']);

            $now = now();
            $expiresAt = $now->copy()->addMinutes($menu->shelf_life ?? 60);

            // ✅ build payload sekali (lebih clean)
            $payload = [
                'menu_id'     => $menu->id,
                'outlet_id'   => $data['outletId'],
                'plate_color' => $menu->plateColor->id, 
                'quantity'    => 1,
                'produced_at' => $now,
                'expires_at'  => $expiresAt,
                'status'      => 'fresh',
            ];

            // ✅ lebih clean pakai collection
            $items = collect()
                ->times($data['quantity'], function () use ($payload) {
                    return $this->create($payload);
                });

            return $items->values();
        });
    }

    /**
     * Piring yang diproduksi menyimpan `plate_color` milik menunya, dan warna
     * itulah unit harganya. Memproduksi menu brand lain di outlet ini berarti
     * memasukkan harga brand lain ke rekonsiliasi hari itu — kesalahan yang
     * baru ketahuan saat closing report tidak balance.
     *
     * Kelonggarannya sama dengan POSService: kalau salah satu sisi belum punya
     * brand (data transisi), tidak ada yang bisa dibandingkan, jadi dibiarkan.
     */
    private function assertMenuBelongsToOutletBrand(Menu $menu, string $outletId): void
    {
        $outletBrandId = $this->brandIdForOutlet($outletId);

        if ($outletBrandId === null || $menu->brand_id === null) {
            return;
        }

        if ($menu->brand_id !== $outletBrandId) {
            throw new BusinessRuleException(
                "Menu '{$menu->menuname}' bukan milik brand outlet ini dan tidak bisa diproduksi di sini."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET CONVEYOR
    |--------------------------------------------------------------------------
    */
    /**
     * Piring yang masih berjalan di belt, dikelompokkan per batch produksi.
     */
    public function conveyorGrouped(string $outletId)
    {
        return $this->groupByBatch($this->onBeltItems($outletId));
    }

    /**
     * Piring hari ini yang belum difinalisasi, apa pun sisi expiry-nya.
     *
     * Read-only. `expires_at` adalah sumber kebenarannya, jadi keadaan belt
     * disaring dari situ, bukan dari kolom `belt_status` yang tersimpan.
     *
     * Dulu ini menjalankan dua UPDATE massal sebelum tiap pembacaan — dan dapur
     * memanggilnya tiap 30 detik per tablet, jadi sebuah GET menulis seluruh
     * baris outlet beberapa kali semenit. Kolom tersimpan sekarang disegarkan
     * `production:refresh-belt-status`; lihat Console\Kernel.
     *
     * `menu.plateColor` di-eager load karena groupByBatch() membaca keduanya.
     * `$this->query()` itu newQuery() polos — ia tidak menerapkan `$relations`,
     * hanya buildQuery() yang melakukannya — jadi tanpa ini setiap baris
     * membangunkan dua query tambahan dan belt 100 piring memakan 201 round trip.
     *
     * Kedua pembaca (`onBeltItems`, `expiredItems`) berbagi definisi ini supaya
     * tidak bisa menyimpang: dua daftar yang saling tumpang tindih berarti
     * piring dihitung dua kali, yang saling berlubang berarti piring tidak bisa
     * ditutup sama sekali dan "Get Data POS" terkunci.
     */
    private function beltQuery(string $outletId)
    {
        return $this->query()
            ->with('menu.plateColor')
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->whereDate('produced_at', today());
    }

    /** Masih berjalan di belt: belum lewat `expires_at`. */
    private function onBeltItems(string $outletId)
    {
        return $this->beltQuery($outletId)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();
    }

    /** Sudah lewat `expires_at`, tapi belum ditutup sold/waste. */
    private function expiredItems(string $outletId)
    {
        return $this->beltQuery($outletId)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Gabungkan piring menjadi batch produksi.
     *
     * Dilakukan di PHP, bukan lewat `GROUP BY` + agregat id di SQL: `string_agg`
     * itu PostgreSQL dan `group_concat` itu SQLite, sementara test berjalan di
     * SQLite dan produksi di PostgreSQL. Pola menghindari SQL khusus driver ini
     * sama dengan yang dipakai WasteAnalysisService. Query tetap mengambil semua
     * baris — yang dihemat adalah JSON-nya, dan itu memang leher botolnya.
     *
     * `groupBy()` mempertahankan urutan kemunculan, jadi group ikut terurut
     * `expires_at` selama pemanggil sudah mengurutkannya.
     */
    private function groupByBatch($items)
    {
        return $items
            ->groupBy(fn ($item) => implode('|', [
                $item->menu_id,
                $item->produced_at?->toIso8601String(),
                $item->expires_at?->toIso8601String(),
            ]))
            ->map(function ($group, $key) {
                // Belt status dihitung sekali per batch, bukan sekali per piring:
                // seluruh anggota group punya `expires_at` yang sama persis.
                $first = $group->first()->updateBeltStatus();

                return [
                    'group_key'        => $key,
                    'menu_id'          => $first->menu_id,
                    'menu_name'        => $first->menu?->menuname,
                    'plate_color'      => $first->plate_color,
                    'plate_color_name' => $first->menu?->plateColor?->platename,
                    'produced_at'      => $first->produced_at,
                    'expires_at'       => $first->expires_at,
                    'belt_status'      => $first->belt_status,
                    // Jumlah baris, bukan SUM(quantity). Satu piring selalu satu
                    // baris ber-quantity 1, dan pemanggil memakai angka ini untuk
                    // mengiris `itemIds` — keduanya harus panjang yang sama.
                    'quantity'         => $group->count(),
                    'item_ids'         => $group->pluck('id')->values()->all(),
                ];
            })
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | GET Expired Items
    |--------------------------------------------------------------------------
    */
    /**
     * Piring yang sudah lewat `expires_at` tapi belum ditutup, dikelompokkan
     * per batch produksi.
     *
     * Halaman inilah yang paling butuh pengelompokan: conveyor hanya menampung
     * piring dalam shelf life (~60 menit), sementara daftar expired menumpuk
     * sepanjang hari sampai operator menutupnya.
     */
    public function expiredGrouped(string $outletId)
    {
        return $this->groupByBatch($this->expiredItems($outletId));
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE Expired Items
    |--------------------------------------------------------------------------
    */
    /**
     * Menutup banyak piring expired sekaligus.
     *
     * Ini bukan sekadar `updateExpired()` di dalam loop. Halaman expired kini
     * menampilkan batch, dan satu batch bisa berisi puluhan piring — versi loop
     * berarti puluhan request di jaringan dapur, artinya puluhan peluang gagal
     * dan puluhan baris antrean offline untuk satu aksi operator.
     *
     * Gerbangnya sama persis dengan jalur per-piring: `expires_at` (bukan
     * `belt_status`, yang bisa tertinggal semenit), `final_status` masih NULL,
     * dan hanya hari produksinya sendiri.
     *
     * Perbedaan yang disengaja: piring dari hari sebelumnya **ditolak seluruh
     * request**, sedangkan piring yang keburu ditutup tablet lain **dilewati**.
     * Yang pertama berarti operator salah hari — laporan kemarin sudah tutup dan
     * tidak boleh bergeser. Yang kedua kejadian normal di tablet bersama, dan
     * membuntukan seluruh batch karena satu piring hanya menyandera operator.
     *
     * @return array{updated:int, skipped:int}
     */
    public function updateExpiredBulk(array $ids, string $status, ?string $notes = null): array
    {
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return ['updated' => 0, 'skipped' => 0];
        }

        // Gerbang yang sama dengan markSold()/markWaste(): hari lalu ditolak,
        // tidak pernah dilewati diam-diam.
        $this->assertWithinProductionDay($ids);

        return DB::transaction(function () use ($ids, $status, $notes) {
            $stamp  = now();
            $column = $status === 'sold' ? 'sold_at' : 'wasted_at';

            // Syaratnya ikut di dalam UPDATE, bukan hanya di SELECT sebelumnya.
            // Dua tablet bisa membuka group yang sama, dan yang kalah harus
            // tidak mengubah apa pun.
            ProductionItem::query()
                ->whereIn('id', $ids)
                ->where('expires_at', '<=', now())
                ->whereNull('final_status')
                ->whereDate('produced_at', today())
                ->update([
                    'final_status' => $status,
                    'notes'        => $notes,
                    $column        => $stamp,
                ]);

            // Baris yang benar-benar kita klaim, dikenali dari stempel waktu yang
            // baru saja ditulis. `update()` hanya mengembalikan jumlah, bukan id,
            // dan untuk waste jumlah saja tidak cukup: mencatat WasteRecord untuk
            // piring yang sudah diambil tablet lain akan menggandakan angka waste
            // hari itu, dan angka itulah yang direkonsiliasi dengan POS.
            $claimed = ProductionItem::query()
                ->whereIn('id', $ids)
                ->where('final_status', $status)
                ->where($column, $stamp)
                ->pluck('id')
                ->all();

            if ($status === 'waste' && $claimed !== []) {
                $this->wasteRecords->recordFromItems(
                    $claimed,
                    $notes ?: 'Marked as waste from expired items'
                );
            }

            return [
                'updated' => count($claimed),
                'skipped' => count($ids) - count($claimed),
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | MARK SOLD
    |--------------------------------------------------------------------------
    */
    public function markSold(array $ids)
    {
        $this->assertWithinProductionDay($ids);

        return $this->query()
            ->whereIn('id', $ids)
            ->whereNull('final_status')
            ->whereDate('produced_at', today())
            ->update([
                'final_status' => 'sold',
                'sold_at' => now(),
            ]);
    }
    public function markWaste(array $ids)
    {
        $this->assertWithinProductionDay($ids);

        return $this->query()
            ->whereIn('id', $ids)
            ->whereNull('final_status')
            ->whereDate('produced_at', today())
            ->update([
                'final_status' => 'waste',
                'wasted_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CLOSE DAY (sisa plate dianggap terjual)
    |--------------------------------------------------------------------------
    | Operator hanya menandai waste per plate. Saat menutup hari, semua plate
    | hari ini yang belum difinalisasi diperlakukan sebagai terjual.
    | Sengaja dibatasi ke hari produksi berjalan: hari sebelumnya sudah ditutup
    | sebagai waste oleh autoWasteCarryOver(), dan laporan lama tidak boleh
    | berubah karena aksi hari ini.
    */
    public function closeDaySold(string $outletId): int
    {
        return $this->query()
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->whereDate('produced_at', today())
            ->update([
                'final_status' => 'sold',
                'sold_at'      => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | BUSINESS-DAY GUARD
    |--------------------------------------------------------------------------
    | Plate hanya boleh ditandai sold/waste pada hari produksinya (produced_at).
    | Mencegah data kemarin ter-update menjadi sold/waste hari ini.
    */
    private function assertWithinProductionDay(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $stale = ProductionItem::whereIn('id', $ids)
            ->whereDate('produced_at', '!=', today()->toDateString())
            ->count();

        if ($stale > 0) {
            throw new BusinessRuleException(
                "Tidak bisa menandai {$stale} plate dari hari sebelumnya. "
                . 'Plate hanya dapat ditandai sold/waste pada hari produksinya.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UNRESOLVED COUNT (gate untuk Get Data POS)
    |--------------------------------------------------------------------------
    | Jumlah plate pada tanggal tertentu yang belum ditandai sold/waste.
    */
    public function countUnresolved(string $outletId, string $date): int
    {
        $date = \Carbon\Carbon::parse($date)->toDateString();

        return ProductionItem::where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->whereDate('produced_at', $date)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO-WASTE CARRY-OVER (saat pergantian hari)
    |--------------------------------------------------------------------------
    | Plate hari-hari sebelumnya yang belum diselesaikan (final_status NULL)
    | otomatis menjadi waste, diatribusikan ke hari produksinya — bukan hari ini.
    */
    public function autoWasteCarryOver(?string $outletId = null): int
    {
        $query = ProductionItem::query()
            ->whereNull('final_status')
            ->whereDate('produced_at', '<', today());

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($items) {
            $reason = 'Auto-waste: plate tidak diselesaikan pada hari produksi';

            foreach ($items as $item) {
                $item->update([
                    'final_status' => 'waste',
                    'belt_status'  => 'expired',
                    // 🔑 atribusi ke hari produksi, bukan now()
                    'wasted_at'    => $item->produced_at,
                    'notes'        => $item->notes ?: $reason,
                ]);

                WasteRecord::create([
                    'production_item_id' => $item->id,
                    'menu_id'            => $item->menu_id,
                    'plate_color'        => $item->plate_color,
                    'quantity'           => $item->quantity ?? 1,
                    'reason'             => $reason,
                    'recorded_at'        => $item->produced_at,
                    'outlet_id'          => $item->outlet_id,
                ]);
            }

            return $items->count();
        });
    }
    public function getSoldItem($outletId, $date)
    {
        return $this->query() 
            ->leftJoin('plate_colors', DB::raw('plate_colors.id::text'), '=', 'production_items.plate_color')
            ->where('production_items.outlet_id', $outletId)
            ->whereDate('production_items.sold_at', $date)
            ->groupBy('plate_colors.id', 'plate_colors.platename')
            ->get([
                'plate_colors.id as plate_color_id',
                'plate_colors.platename as plate_color_name',
                DB::raw('SUM(production_items.quantity) as quantity'),
            ]);
    }

    public function getWasteItem($outletId, $date)
    {
        return $this->query() 
            ->leftJoin('plate_colors', DB::raw('plate_colors.id::text'), '=', 'production_items.plate_color')
            ->where('production_items.outlet_id', $outletId)
            ->whereDate('production_items.wasted_at', $date)
            ->groupBy('plate_colors.id', 'plate_colors.platename')
            ->get([
                'plate_colors.id as plate_color_id',
                'plate_colors.platename as plate_color_name',
                DB::raw('SUM(production_items.quantity) as quantity'),
            ]);
    }
 
    public function getProductionMenuDetail(
        string $outletId,
        string $date,
        string $plateColorId
    ) {

    $date = \Carbon\Carbon::parse($date)->toDateString();

    return ProductionItem::selectRaw("
            menu_id,
            sum(quantity) as total_produced,
            SUM(CASE WHEN sold_at IS NOT NULL THEN quantity ELSE 0 END) as total_sold,
            SUM(CASE WHEN wasted_at IS NOT NULL THEN quantity ELSE 0 END) as total_wasted
        ")
        ->where('outlet_id', $outletId)
        ->whereDate('produced_at', $date)
        ->where('plate_color', $plateColorId)
        ->groupBy('menu_id')
        ->with('menu')
        ->get();
    } 
 
    public function getProductionList(
        string $outletId,
        string $date
    ) {
    $date = \Carbon\Carbon::parse($date)->toDateString();
    return ProductionItem::where('outlet_id', $outletId)
        ->whereDate('produced_at', $date)
        ->with('menu')
        ->get();
    }
}
