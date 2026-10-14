<?php

namespace App\Services\Production;

use App\Exceptions\BusinessRuleException;
use App\Models\Menu;
use App\Models\PlateColors;
use App\Models\ProductionItem;
use App\Models\ProductionStatusCorrection;
use App\Models\ProductionStatusCorrectionItem;
use App\Models\SalesHeader;
use App\Models\User;
use App\Models\WasteReason;
use App\Models\WasteRecord;
use App\Services\BaseService;
use App\Services\Sales\SalesService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi Status Produksi: mengubah `final_status` piring yang SUDAH final,
 * sold → waste atau waste → sold. Rancangannya di
 * docs/production-status-correction-plan.md.
 *
 * Ini pengecualian yang disengaja dari aturan "final_status sekali diisi,
 * final" dan "finalisasi hanya di hari produksi". Aturan yang tetap dipegang:
 *
 * - **Atribusi tidak bergeser.** Stempel kolom tujuan diisi stempel lama,
 *   bukan `now()`. `totalsByPlateColor()` (Get Data POS) membaca
 *   `whereDate(sold_at|wasted_at)`; stempel baru di hari lain memindahkan
 *   piring ke laporan hari lain. Kolom asal dikosongkan — rincian menu dan
 *   payload BI menghitung `sold_at IS NOT NULL` / `wasted_at IS NOT NULL`,
 *   jadi dua-duanya terisi berarti satu piring terhitung dua kali.
 * - **Hanya sebelum Sales Input ada.** Begitu Sales Input tanggal itu
 *   disimpan — draft sekalipun — tanggalnya terkunci. `sales_items` menyimpan
 *   snapshot angka produksi, dan closing serta BI dibangun di atasnya;
 *   mengoreksi di bawahnya membuat semuanya menyimpang dari sumbernya.
 *   Layar Sales Input yang sudah menarik angka sebelum koreksi dijaga
 *   terpisah oleh SalesService (tanggal yang pernah dikoreksi wajib
 *   menyimpan angka terbaru).
 * - **Semua atau tidak sama sekali.** Admin meminta angka tertentu, jadi piring
 *   yang keburu berubah di tengah jalan membatalkan seluruh koreksi — beda
 *   dengan `updateExpiredBulk()` yang melewatinya.
 */
class ProductionStatusCorrectionService extends BaseService
{
    protected string $model = ProductionStatusCorrection::class;

    public const STATUSES = ['sold', 'waste'];

    public function __construct(
        private readonly SalesService $sales,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | SUMMARY — layar langkah 1
    |--------------------------------------------------------------------------
    */

    /**
     * Angka sold/waste per menu untuk outlet + hari produksi, plus status
     * kunci tanggal itu. Tidak menolak tanggal yang terkunci — layar perlu
     * menampilkan angkanya bersama alasan kenapa tidak bisa diubah.
     */
    public function summary(string $outletId, string $date): array
    {
        $day = $this->parseDay($date);

        $menus = $this->currentTotals($outletId, $day)
            ->map(fn (array $row) => [
                'menuId'         => $row['menuId'],
                'menuCode'       => $row['menuCode'],
                'menuName'       => $row['menuName'],
                'plateColorId'   => $row['plateColorId'],
                'plateColorName' => $row['plateColorName'],
                'plateColorHex'  => $row['plateColorHex'],
                'sold'           => $row['sold'],
                'waste'          => $row['waste'],
            ])
            ->values()
            ->all();

        $unresolved = ProductionItem::query()
            ->where('outlet_id', $outletId)
            ->whereDate('produced_at', $day->toDateString())
            ->whereNull('final_status')
            ->count();

        $cancelled = $this->sales->cancelledDrafts($outletId, $day->toDateString());
        $names     = $this->userNames($cancelled->pluck('deleted_by'));

        return [
            'date'       => $day->toDateString(),
            'lock'       => $this->lockState($outletId, $day),
            'unresolved' => $unresolved,
            'menus'      => $menus,
            // Draft Sales Input tanggal ini yang dibatalkan admin, terbaru dulu.
            'cancelledSalesDrafts' => $cancelled
                ->map(fn (SalesHeader $h) => [
                    'id'              => $h->id,
                    'reason'          => $h->deletion_reason,
                    'cancelledBy'     => $h->deleted_by !== null ? trim((string) $h->deleted_by) : null,
                    'cancelledByName' => $names[(int) trim((string) $h->deleted_by)] ?? null,
                    'cancelledAt'     => $h->deleted_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PREVIEW — tidak menulis apa pun
    |--------------------------------------------------------------------------
    */

    public function preview(array $data): array
    {
        $day = $this->parseDay($data['date']);

        $this->assertDayInRange($day);

        $lock = $this->lockState($data['outletId'], $day);
        $this->assertUnlocked($lock);

        $current = $this->currentTotals($data['outletId'], $day);
        $lines   = $this->validateLines($data, $current);

        return $this->buildImpact($day, $lock, $current, $lines);
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY
    |--------------------------------------------------------------------------
    */

    public function apply(array $data): ProductionStatusCorrection
    {
        $day      = $this->parseDay($data['date']);
        $outletId = $data['outletId'];

        $this->assertDayInRange($day);

        $correctionId = DB::transaction(function () use ($data, $day, $outletId) {

            // Dicek ulang di dalam transaksi: Sales Input bisa saja disimpan
            // di antara preview dan simpan.
            $lock = $this->lockState($outletId, $day);
            $this->assertUnlocked($lock);

            $current = $this->currentTotals($outletId, $day);
            $lines   = $this->validateLines($data, $current);

            // Semua piring dipilih DULU, baru diubah. Kalau satu menu dikoreksi
            // dua arah sekaligus, memilih sambil mengubah bisa mengambil piring
            // yang baru saja dipindah oleh baris sebelumnya.
            $picked = [];
            foreach ($lines as $i => $line) {
                $picked[$i] = $this->pickItems($outletId, $day, $line);
            }

            $wasteReason = !empty($data['wasteReasonId'])
                ? WasteReason::find($data['wasteReasonId'])
                : null;

            $correction = ProductionStatusCorrection::create([
                'outlet_id'       => $outletId,
                'production_date' => $day->toDateString(),
                'reason'          => $data['reason'],
                'waste_reason_id' => $wasteReason?->id,
                'total_to_waste'  => collect($lines)->where('to', 'waste')->sum('quantity'),
                'total_to_sold'   => collect($lines)->where('to', 'sold')->sum('quantity'),
            ]);

            foreach ($lines as $i => $line) {
                foreach ($picked[$i] as $item) {
                    $this->flip($correction, $item, $line['from'], $line['to'], $wasteReason);
                }
            }

            return $correction->id;
        });

        return $this->show($correctionId);
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORY
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{outletId: string, startDate?: ?string, endDate?: ?string, per_page?: ?int}  $filters
     */
    public function history(array $filters): LengthAwarePaginator
    {
        $query = ProductionStatusCorrection::query()
            ->with(['outlet', 'wasteReason', 'items.menu' => fn ($q) => $q->withTrashed()])
            ->where('outlet_id', $filters['outletId']);

        if (!empty($filters['startDate'])) {
            $query->whereDate('production_date', '>=', $filters['startDate']);
        }

        if (!empty($filters['endDate'])) {
            $query->whereDate('production_date', '<=', $filters['endDate']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $page = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $this->attachCreatorNames($page->getCollection());

        return $page;
    }

    public function show(string $id): ProductionStatusCorrection
    {
        $correction = ProductionStatusCorrection::with([
            'outlet',
            'wasteReason',
            'items.menu' => fn ($q) => $q->withTrashed(),
        ])->findOrFail($id);

        $this->attachCreatorNames(collect([$correction]));

        return $correction;
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNAL
    |--------------------------------------------------------------------------
    */

    private function parseDay(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    private function assertDayInRange(Carbon $day): void
    {
        // Sengaja tanpa batas hari ke belakang: yang menentukan boleh-tidaknya
        // koreksi adalah Sales Input (lockState()), bukan umur tanggalnya.
        if ($day->gt(today())) {
            throw new BusinessRuleException('Tanggal koreksi tidak boleh di masa depan.');
        }
    }

    /**
     * Apakah tanggal itu masih boleh dikoreksi, dan kenapa tidak.
     *
     * `salesId` diisi supaya layar bisa menawarkan pembatalan draft
     * (`DELETE /sales/{id}`) — jalan keluar dari kunci ini.
     *
     * @return array{locked: bool, reason: ?string, salesId: ?string, salesStatus: ?string, warnings: array<int, string>}
     */
    public function lockState(string $outletId, Carbon $day): array
    {
        $sales = SalesHeader::query()
            ->where('outlet_id', $outletId)
            ->whereDate('date', $day->toDateString())
            ->first();

        $reason = null;

        if ($day->gt(today())) {
            $reason = 'Tanggal koreksi tidak boleh di masa depan.';
        } elseif ($sales) {
            $reason = "Sales Input tanggal ini sudah dibuat (status {$sales->status}). "
                . 'Koreksi hanya bisa dilakukan sebelum Sales Input disimpan'
                . ($sales->status === 'draft' ? ' — batalkan draft-nya dulu.' : '.');
        }

        // Get Data POS tidak meninggalkan jejak, jadi tidak bisa diketahui
        // apakah layar Sales Input sudah memegang angka lama.
        $warnings = $reason === null
            ? ['Kalau operator sudah membuka Get Data POS untuk tanggal ini, minta ulangi setelah koreksi — menyimpan dengan angka lama akan ditolak.']
            : [];

        return [
            'locked'      => $reason !== null,
            'reason'      => $reason,
            'salesId'     => $sales?->id,
            'salesStatus' => $sales?->status,
            'warnings'    => $warnings,
        ];
    }

    private function assertUnlocked(array $lock): void
    {
        if ($lock['locked']) {
            throw new BusinessRuleException($lock['reason']);
        }
    }

    /**
     * Piring final per menu pada hari produksi itu, dihitung dari
     * `final_status`. Dikunci per menu — `menu_id` menentukan `plate_color`.
     *
     * @return Collection<string, array> menuId => baris
     */
    private function currentTotals(string $outletId, Carbon $day): Collection
    {
        $rows = ProductionItem::query()
            ->where('outlet_id', $outletId)
            ->whereDate('produced_at', $day->toDateString())
            ->whereNotNull('final_status')
            ->whereNotNull('menu_id')
            ->groupBy('menu_id', 'plate_color')
            ->selectRaw("
                menu_id,
                plate_color,
                SUM(CASE WHEN final_status = 'sold' THEN quantity ELSE 0 END) as sold,
                SUM(CASE WHEN final_status = 'waste' THEN quantity ELSE 0 END) as waste
            ")
            ->get();

        // `withTrashed` — menu atau warna yang sudah dihapus tetap punya
        // piring di hari-hari sebelumnya.
        $menus = Menu::withTrashed()
            ->whereIn('id', $rows->pluck('menu_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $colors = PlateColors::withTrashed()
            ->whereIn('id', $rows->pluck('plate_color')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($menus, $colors) {
                $menu  = $menus[$row->menu_id] ?? null;
                $color = $colors[$row->plate_color] ?? null;

                return [
                    'menuId'         => $row->menu_id,
                    'menuCode'       => $menu?->code,
                    'menuName'       => $menu?->menuname ?? '(menu terhapus)',
                    'plateColorId'   => $row->plate_color,
                    'plateColorName' => $color?->platename,
                    'plateColorHex'  => $color?->color_hex,
                    'sold'           => (int) $row->sold,
                    'waste'          => (int) $row->waste,
                ];
            })
            // Satu menu bisa muncul dua kali kalau warnanya pernah diganti di
            // tengah hari. Digabung per menu — pemilihan piring di pickItems()
            // juga per menu, tidak peduli warnanya.
            ->groupBy('menuId')
            ->map(function (Collection $group) {
                $first = $group->first();
                $first['sold']  = $group->sum('sold');
                $first['waste'] = $group->sum('waste');

                return $first;
            })
            ->sortBy('menuName', SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * @return array<int, array{menuId: string, from: string, to: string, quantity: int}>
     */
    private function validateLines(array $data, Collection $current): array
    {
        $lines = [];
        $seen  = [];

        foreach ($data['items'] as $raw) {
            $line = [
                'menuId'   => (string) $raw['menuId'],
                'from'     => (string) $raw['from'],
                'to'       => (string) $raw['to'],
                'quantity' => (int) $raw['quantity'],
            ];

            if ($line['from'] === $line['to']) {
                throw new BusinessRuleException('Status asal dan tujuan tidak boleh sama.');
            }

            $key = $line['menuId'] . '|' . $line['from'];

            if (isset($seen[$key])) {
                throw new BusinessRuleException('Satu menu tidak boleh dikoreksi dua kali dengan arah yang sama.');
            }

            $seen[$key] = true;

            $row = $current[$line['menuId']] ?? null;

            if (!$row) {
                throw new BusinessRuleException(
                    'Menu tidak punya piring final di outlet dan tanggal ini.'
                );
            }

            $available = $row[$line['from']];

            if ($line['quantity'] > $available) {
                throw new BusinessRuleException(
                    "{$row['menuName']} hanya punya {$available} piring {$line['from']} di tanggal ini, "
                    . "tidak bisa mengoreksi {$line['quantity']}."
                );
            }

            $lines[] = $line;
        }

        $toWaste = collect($lines)->contains(fn ($l) => $l['to'] === 'waste');

        if ($toWaste && empty($data['wasteReasonId'])) {
            throw new BusinessRuleException('Alasan waste wajib dipilih untuk koreksi ke waste.');
        }

        return $lines;
    }

    /**
     * Piring konkret untuk satu baris koreksi. Deterministik — `produced_at`
     * terbaru dulu, lalu `id` — supaya preview dan simpan memilih hal yang sama
     * selama datanya tidak berubah.
     */
    private function pickItems(string $outletId, Carbon $day, array $line): Collection
    {
        $items = ProductionItem::query()
            ->where('outlet_id', $outletId)
            ->where('menu_id', $line['menuId'])
            ->whereDate('produced_at', $day->toDateString())
            ->where('final_status', $line['from'])
            ->orderByDesc('produced_at')
            ->orderByDesc('id')
            ->limit($line['quantity'])
            ->lockForUpdate()
            ->get();

        if ($items->count() < $line['quantity']) {
            throw new BusinessRuleException(
                'Jumlah piring berubah selagi koreksi diproses. Muat ulang lalu coba lagi.',
                409
            );
        }

        return $items;
    }

    private function flip(
        ProductionStatusCorrection $correction,
        ProductionItem $item,
        string $from,
        string $to,
        ?WasteReason $wasteReason
    ): void {
        $oldSoldAt   = $item->sold_at;
        $oldWastedAt = $item->wasted_at;

        // Stempel lama dibawa ke kolom tujuan; `produced_at` kalau stempelnya
        // kosong (data rusak) — sama dengan autoWasteCarryOver().
        $stamp = ($from === 'sold' ? $oldSoldAt : $oldWastedAt) ?? $item->produced_at;

        $changes = $to === 'waste'
            ? ['final_status' => 'waste', 'wasted_at' => $stamp, 'sold_at' => null]
            : ['final_status' => 'sold', 'sold_at' => $stamp, 'wasted_at' => null];

        // Syaratnya di dalam UPDATE: proses lain yang keburu mengubah piring
        // ini tidak boleh tertimpa.
        $affected = ProductionItem::query()
            ->whereKey($item->id)
            ->where('final_status', $from)
            ->update($changes + ['updated_by' => auth()->id()]);

        if ($affected !== 1) {
            throw new BusinessRuleException(
                'Ada piring yang berubah selagi koreksi diproses. Muat ulang lalu coba lagi.',
                409
            );
        }

        $wasteRecordId = $to === 'waste'
            ? $this->recordWaste($item, $stamp, $wasteReason)
            : $this->removeWaste($item);

        ProductionStatusCorrectionItem::create([
            'correction_id'      => $correction->id,
            'production_item_id' => $item->id,
            'menu_id'            => $item->menu_id,
            'plate_color'        => $item->plate_color,
            'from_status'        => $from,
            'to_status'          => $to,
            'old_sold_at'        => $oldSoldAt,
            'old_wasted_at'      => $oldWastedAt,
            'waste_record_id'    => $wasteRecordId,
        ]);
    }

    /**
     * `recorded_at` = stempel waste piring, bukan now(): analisis waste
     * mengelompokkan per `recorded_at`, dan koreksi untuk hari lalu harus
     * masuk ke hari itu.
     */
    private function recordWaste(ProductionItem $item, $stamp, ?WasteReason $wasteReason): string
    {
        return WasteRecord::create([
            'production_item_id' => $item->id,
            'menu_id'            => $item->menu_id,
            'plate_color'        => $item->plate_color,
            'quantity'           => $item->quantity ?? 1,
            'reason'             => $wasteReason?->reason_name ?? 'Koreksi status produksi',
            ...app(WasteReasonResolver::class)->forReason($wasteReason),
            'recorded_at'        => $stamp,
            'outlet_id'          => $item->outlet_id,
        ])->id;
    }

    /**
     * Piring yang ternyata terjual tidak boleh tersisa di analisis waste.
     * Soft delete, jadi jejaknya masih ada. Semua baris piring itu ikut —
     * `recordFromItems()` tidak mencegah duplikat.
     */
    private function removeWaste(ProductionItem $item): ?string
    {
        $records = WasteRecord::query()
            ->where('production_item_id', $item->id)
            ->get();

        $records->each->delete();

        return $records->first()?->id;
    }

    private function buildImpact(Carbon $day, array $lock, Collection $current, array $lines): array
    {
        $byMenu = [];

        foreach ($lines as $line) {
            $row = $current[$line['menuId']];
            $id  = $line['menuId'];

            $byMenu[$id] ??= [
                'menuId'         => $id,
                'menuCode'       => $row['menuCode'],
                'menuName'       => $row['menuName'],
                'plateColorId'   => $row['plateColorId'],
                'plateColorName' => $row['plateColorName'],
                'soldBefore'     => $row['sold'],
                'wasteBefore'    => $row['waste'],
                'soldAfter'      => $row['sold'],
                'wasteAfter'     => $row['waste'],
            ];

            $sign = $line['to'] === 'waste' ? 1 : -1;

            $byMenu[$id]['wasteAfter'] += $sign * $line['quantity'];
            $byMenu[$id]['soldAfter']  -= $sign * $line['quantity'];
        }

        // Per warna: unit yang direkonsiliasi dengan POS. Semua menu warna itu
        // dihitung, bukan hanya yang dikoreksi, supaya angkanya sama dengan
        // yang akan dilihat operator di Sales Input.
        $touchedColors = collect($byMenu)->pluck('plateColorId')->unique();

        $byColor = $current
            ->filter(fn ($row) => $touchedColors->contains($row['plateColorId']))
            ->groupBy('plateColorId')
            ->map(function (Collection $rows, $colorId) use ($byMenu) {
                $soldBefore  = $rows->sum('sold');
                $wasteBefore = $rows->sum('waste');

                $delta = collect($byMenu)
                    ->where('plateColorId', $colorId)
                    ->sum(fn ($m) => $m['wasteAfter'] - $m['wasteBefore']);

                return [
                    'plateColorId'   => $colorId,
                    'plateColorName' => $rows->first()['plateColorName'],
                    'soldBefore'     => $soldBefore,
                    'wasteBefore'    => $wasteBefore,
                    'soldAfter'      => $soldBefore - $delta,
                    'wasteAfter'     => $wasteBefore + $delta,
                ];
            })
            ->sortBy('plateColorName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'date'        => $day->toDateString(),
            'lock'        => $lock,
            'menus'       => array_values($byMenu),
            'plateColors' => $byColor,
            'totals'      => [
                'toWaste' => collect($lines)->where('to', 'waste')->sum('quantity'),
                'toSold'  => collect($lines)->where('to', 'sold')->sum('quantity'),
            ],
        ];
    }

    /**
     * @param  Collection<int, ProductionStatusCorrection>  $corrections
     */
    private function attachCreatorNames(Collection $corrections): void
    {
        $names = $this->userNames($corrections->pluck('created_by'));

        foreach ($corrections as $correction) {
            $correction->setAttribute(
                'creator_name',
                $names[(int) trim((string) $correction->created_by)] ?? null
            );
        }
    }

    /**
     * Nama user dari kolom userstamp. Kolomnya char sementara `users.id`
     * bigint, jadi id yang bukan angka dibuang sebelum menyentuh query.
     *
     * @return Collection<int, string> id => name
     */
    private function userNames(Collection $ids): Collection
    {
        $ids = $ids
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $ids->all())->pluck('name', 'id');
    }
}
