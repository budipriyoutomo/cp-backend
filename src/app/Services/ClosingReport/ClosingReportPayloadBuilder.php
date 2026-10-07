<?php

namespace App\Services\ClosingReport;

use App\Models\ClosingReport;
use App\Models\Menu;
use App\Models\ProductionItem;
use App\Models\SalesItemDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Payload `closingreport.submitted` v1 untuk BI. Bentuknya kontrak dengan
 * pihak luar — mengubah field berarti menaikkan `version`.
 *
 * `sentAt` sengaja tidak diisi di sini: payload disimpan di outbox saat submit,
 * sedangkan waktu kirim baru diketahui saat publish (bisa jauh setelahnya kalau
 * broker sempat mati). Lihat ClosingReportOutboxService::publish().
 */
class ClosingReportPayloadBuilder
{
    public const EVENT = 'closingreport.submitted';
    public const VERSION = 1;

    public function build(ClosingReport $report): array
    {
        $report->loadMissing('outlet.brandMaster');

        $outlet = $report->outlet;
        $brand = $outlet->brandMaster;

        return [
            'event'     => self::EVENT,
            'version'   => self::VERSION,
            'messageId' => $report->id,
            'data'      => [
                'closingReportId' => $report->id,
                'date'            => $report->date->toDateString(),
                'outlet'          => ['code' => $outlet->code, 'name' => $outlet->name],
                'brand'           => $brand ? ['code' => $brand->code, 'name' => $brand->name] : null,
                'items'           => $this->items($report),
            ],
        ];
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
}
