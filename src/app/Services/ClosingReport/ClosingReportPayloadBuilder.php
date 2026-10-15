<?php

namespace App\Services\ClosingReport;

use App\Models\ClosingReport;
use App\Models\Menu;
use App\Models\ProductionItem;
use App\Models\SalesItemDetail;
use App\Models\WasteCategory;
use App\Models\WasteRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Payload `closingreport.submitted` untuk BI. Bentuknya kontrak dengan
 * pihak luar — mengubah field berarti menaikkan `version`.
 *
 * - v1: angka per menu.
 * - v2: v1 + `items[].wasteByCategory` (docs/jawaban-bi-closing-report-v2.md).
 *
 * Versi yang disusun dipilih lewat config `rabbitmq.closing_report.payload_version`,
 * bukan otomatis: consumer BI harus sudah menerima v2 sebelum kita mengirimnya.
 *
 * `sentAt` sengaja tidak diisi di sini: payload disimpan di outbox saat submit,
 * sedangkan waktu kirim baru diketahui saat publish (bisa jauh setelahnya kalau
 * broker sempat mati). Lihat ClosingReportOutboxService::publish().
 */
class ClosingReportPayloadBuilder
{
    public const EVENT = 'closingreport.submitted';
    public const VERSION_1 = 1;
    public const VERSION_2 = 2;

    public function build(ClosingReport $report): array
    {
        $report->loadMissing('outlet.brandMaster');

        $outlet = $report->outlet;
        $brand = $outlet->brandMaster;
        $version = $this->version();

        $items = $this->items($report);

        if ($version === self::VERSION_2) {
            $items = $this->withWasteByCategory($report, $items);
        }

        return [
            'event'     => self::EVENT,
            'version'   => $version,
            'messageId' => $report->id,
            'data'      => [
                'closingReportId' => $report->id,
                'date'            => $report->date->toDateString(),
                'outlet'          => ['code' => $outlet->code, 'name' => $outlet->name],
                'brand'           => $brand ? ['code' => $brand->code, 'name' => $brand->name] : null,
                'items'           => $items,
            ],
        ];
    }

    /**
     * Nilai dari .env selalu string. Selain 2, semuanya v1 — satu-satunya
     * versi yang pasti diterima consumer BI.
     */
    private function version(): int
    {
        return (int) config('rabbitmq.closing_report.payload_version', self::VERSION_1) === self::VERSION_2
            ? self::VERSION_2
            : self::VERSION_1;
    }

    /**
     * Satu baris per menu: gabungan menu yang diproduksi hari itu dan menu yang
     * punya rincian di Sales Input — sama dengan baris di layar Closing Report
     * (SalesClosingReportResource).
     *
     * Rincian per menu (`sales_item_details`) hanya ada kalau operator membuka
     * dialog rincian di Sales Input. Dulu payload dibangun dari tabel itu saja,
     * jadi sales yang disubmit tanpa membuka dialog terkirim dengan `items: []`
     * dan ditolak BI. Sekarang:
     * - `sold`/`waste` dari rincian kalau ada (dibekukan saat Sales Input),
     *   kalau tidak dari production_items. Rincian itu sendiri dihitung dari
     *   query production_items yang sama, jadi keduanya sepakat.
     * - `adjustment`/`compensation` hanya ada di rincian; tanpa rincian = 0.
     */
    private function items(ClosingReport $report): array
    {
        $details = SalesItemDetail::query()
            ->whereHas('item', fn ($q) => $q->where('sales_id', $report->sales_id))
            ->get()
            ->groupBy('menu_id');

        $production = $this->productionByMenu($report);

        $menuIds = $production->keys()->merge($details->keys())->filter()->unique()->values();

        $menus = Menu::withTrashed()->whereIn('id', $menuIds)->get()->keyBy('id');

        return $menuIds
            ->map(function (string $menuId) use ($details, $production, $menus) {
                $rows = $details->get($menuId);
                $made = $production->get($menuId);
                $menu = $menus->get($menuId);

                return [
                    'menuId'         => $menuId,
                    'menuCode'       => $menu?->code,
                    'menuName'       => $rows?->first()->menu_name ?? $menu?->menuname,
                    'productionDate' => $made ? Carbon::parse($made->first_produced_at)->toDateString() : null,
                    'sold'           => (int) ($rows ? $rows->sum('total_sold') : ($made->sold ?? 0)),
                    'waste'          => (int) ($rows ? $rows->sum('total_wasted') : ($made->waste ?? 0)),
                    'adjustment'     => (int) ($rows?->sum('adjustment') ?? 0),
                    'compensation'   => (int) ($rows?->sum('compensation') ?? 0),
                ];
            })
            ->sortBy('menuName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Angka produksi per menu di outlet + hari laporan.
     *
     * Filternya sama dengan ProductionItemService::getProductionMenuDetail
     * (`whereDate(produced_at)` di zona waktu aplikasi), sumber angka rincian
     * Sales Input. `productionDate` dibaca dari sini — bukan disalin dari
     * tanggal closing.
     */
    private function productionByMenu(ClosingReport $report): Collection
    {
        return ProductionItem::query()
            ->where('outlet_id', $report->outlet_id)
            ->whereNotNull('menu_id')
            ->whereDate('produced_at', $report->date->toDateString())
            ->groupBy('menu_id')
            ->selectRaw('
                menu_id,
                MIN(produced_at) as first_produced_at,
                SUM(CASE WHEN sold_at IS NOT NULL THEN quantity ELSE 0 END) as sold,
                SUM(CASE WHEN wasted_at IS NOT NULL THEN quantity ELSE 0 END) as waste
            ')
            ->get()
            ->keyBy('menu_id');
    }

    /**
     * v2: tempel `wasteByCategory` ke setiap item.
     *
     * Jaminan ke BI: total quantity = `waste` item. `waste` bisa datang dari
     * rincian Sales Input (dibekukan), sedangkan kategorinya dari piring di
     * production_items, jadi selisih keduanya disesuaikan di reconcile().
     */
    private function withWasteByCategory(ClosingReport $report, array $items): array
    {
        $byMenu = $this->categoryCountsByMenu($report);

        return array_map(function (array $item) use ($report, $byMenu) {
            $counts = $byMenu[$item['menuId']] ?? [];
            $counted = array_sum(array_column($counts, 'quantity'));

            if ($counted !== $item['waste']) {
                Log::warning('closingreport: rincian kategori waste disesuaikan ke angka waste', [
                    'closingReportId' => $report->id,
                    'menuId'          => $item['menuId'],
                    'waste'           => $item['waste'],
                    'categorized'     => $counted,
                ]);
            }

            $item['wasteByCategory'] = $this->sorted($this->reconcile($counts, $item['waste']));

            return $item;
        }, $items);
    }

    /**
     * Piring waste di outlet + hari laporan (filter sama dengan
     * productionByMenu()), dikelompokkan per menu lalu per kategori.
     *
     * Kategori = snapshot di waste_records, bukan mapping reason terkini.
     * Satu piring dihitung sekali: `recordFromItems()` tidak mencegah
     * duplikat, jadi yang dipakai record hidup yang tercatat pertama. Piring
     * tanpa record, atau record tanpa kategori, masuk UNCATEGORIZED.
     *
     * @return array<string, array<string, array{categoryCode: string, categoryName: string, quantity: int}>>
     */
    private function categoryCountsByMenu(ClosingReport $report): array
    {
        $plates = ProductionItem::query()
            ->where('outlet_id', $report->outlet_id)
            ->whereNotNull('menu_id')
            ->whereDate('produced_at', $report->date->toDateString())
            ->whereNotNull('wasted_at');

        $categoryOfPlate = WasteRecord::query()
            ->whereIn('production_item_id', (clone $plates)->select('id'))
            ->orderBy('recorded_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['production_item_id', 'waste_category_id'])
            ->unique('production_item_id')
            ->pluck('waste_category_id', 'production_item_id');

        $categories = WasteCategory::withTrashed()
            ->whereIn('id', $categoryOfPlate->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($plates->get(['id', 'menu_id', 'quantity']) as $plate) {
            $category = $categories->get($categoryOfPlate->get($plate->id));
            $code = $category?->code ?? WasteCategory::UNCATEGORIZED_CODE;

            $result[$plate->menu_id][$code] ??= [
                'categoryCode' => $code,
                'categoryName' => $category?->category_name ?? WasteCategory::UNCATEGORIZED_NAME,
                'quantity'     => 0,
            ];
            $result[$plate->menu_id][$code]['quantity'] += (int) ($plate->quantity ?? 1);
        }

        return $result;
    }

    /**
     * Samakan total dengan `waste`. Kurang → selisihnya ke UNCATEGORIZED.
     * Lebih → dipangkas dari UNCATEGORIZED dulu, lalu dari kategori terkecil
     * (kebalikan urutan kirim), supaya kategori yang pasti tersisa paling lama.
     *
     * @param  array<string, array{categoryCode: string, categoryName: string, quantity: int}>  $counts
     */
    private function reconcile(array $counts, int $waste): array
    {
        $diff = $waste - array_sum(array_column($counts, 'quantity'));
        $uncategorized = WasteCategory::UNCATEGORIZED_CODE;

        if ($diff > 0) {
            $counts[$uncategorized] ??= [
                'categoryCode' => $uncategorized,
                'categoryName' => WasteCategory::UNCATEGORIZED_NAME,
                'quantity'     => 0,
            ];
            $counts[$uncategorized]['quantity'] += $diff;

            return $counts;
        }

        $excess = -$diff;
        $trimOrder = array_reverse($this->sorted($counts));
        usort($trimOrder, fn ($a, $b) => ($b['categoryCode'] === $uncategorized) <=> ($a['categoryCode'] === $uncategorized));

        foreach ($trimOrder as $row) {
            if ($excess === 0) {
                break;
            }

            $take = min($excess, $row['quantity']);
            $counts[$row['categoryCode']]['quantity'] -= $take;
            $excess -= $take;
        }

        return array_filter($counts, fn ($row) => $row['quantity'] > 0);
    }

    /**
     * Quantity turun, lalu categoryCode naik — `--rebuild` harus menghasilkan
     * isi yang identik.
     */
    private function sorted(array $counts): array
    {
        $rows = array_values($counts);

        usort($rows, fn ($a, $b) => [$b['quantity'], $a['categoryCode']] <=> [$a['quantity'], $b['categoryCode']]);

        return $rows;
    }
}
