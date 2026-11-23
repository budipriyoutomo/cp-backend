<?php

namespace App\Services\ClosingReport;

use App\Models\Menu;
use App\Models\PlateColors;
use App\Models\ProductionItem;
use App\Models\SalesItemDetail;
use App\Support\Uuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Baris per menu sebuah closing report — **satu-satunya** perhitungannya.
 *
 * Dipakai layar Closing Report (`SalesClosingReportResource`) dan payload BI
 * (`ClosingReportPayloadBuilder`). Dulu keduanya menghitung sendiri dan
 * menyimpang: layar memakai `menus.price` (dibulatkan ke bawah), payload harga
 * plate color; layar `sold`/`waste` dari produksi, payload dari rincian Sales
 * Input. Akibatnya angka rupiah di layar ≠ angka di BI. Jangan hitung baris
 * per menu di tempat lain.
 *
 * Aturannya:
 * - Menu = gabungan menu yang diproduksi di outlet+hari itu dan menu yang punya
 *   rincian di Sales Input.
 * - `sold`/`waste` dari rincian kalau ada (dibekukan saat Sales Input), kalau
 *   tidak dari production_items. `adjustment`/`compensation` hanya ada di
 *   rincian; tanpa rincian = 0.
 * - Warna piring = warna yang tercatat di production_items hari itu (piring
 *   yang benar-benar dibuat), kalau tidak ada warna menu. Harga satuan = harga
 *   warna itu — warna adalah unit harga, bukan `menus.price`. Warna yang sudah
 *   di-soft-delete tetap dibaca, jadi harga selalu ada.
 * - Diurutkan `menuName` (natural, tanpa beda huruf besar-kecil).
 */
class ClosingReportMenuLines
{
    /**
     * @return Collection<int, array{
     *     menuId: string, menu: ?Menu, menuCode: ?string, menuName: ?string,
     *     plateColor: ?PlateColors, unitPrice: float, productionDate: ?string,
     *     produced: int, sold: int, waste: int, adjustment: int, compensation: int
     * }>
     */
    public function for(?string $salesId, string $outletId, string $date): Collection
    {
        // Laporan tanpa sales_id: barisnya dari produksi saja.
        $details = $salesId === null
            ? collect()
            : SalesItemDetail::query()
                ->whereHas('item', fn ($q) => $q->where('sales_id', $salesId))
                ->get()
                ->groupBy('menu_id');

        $production = $this->productionByMenu($outletId, $date);

        $menuIds = $production->keys()->merge($details->keys())->filter()->unique()->values();

        $menus = Menu::withTrashed()->whereIn('id', $menuIds)->get()->keyBy('id');

        $plateColorIds = $production->pluck('plate_color')
            ->merge($menus->pluck('plate_color_id'))
            ->filter(fn ($id) => Uuid::matches($id))
            ->unique()
            ->values();

        $plateColors = PlateColors::withTrashed()->whereIn('id', $plateColorIds)->get()->keyBy('id');

        return $menuIds
            ->map(function (string $menuId) use ($details, $production, $menus, $plateColors) {
                $rows = $details->get($menuId);
                $made = $production->get($menuId);
                $menu = $menus->get($menuId);
                $plateColor = $plateColors->get($made?->plate_color) ?? $plateColors->get($menu?->plate_color_id);

                return [
                    'menuId'         => $menuId,
                    'menu'           => $menu,
                    'menuCode'       => $menu?->code,
                    'menuName'       => $rows?->first()->menu_name ?? $menu?->menuname,
                    'plateColor'     => $plateColor,
                    'unitPrice'      => round((float) $plateColor?->price, 2),
                    'productionDate' => $made ? Carbon::parse($made->first_produced_at)->toDateString() : null,
                    'produced'       => (int) ($made->produced ?? 0),
                    'sold'           => (int) ($rows ? $rows->sum('total_sold') : ($made->sold ?? 0)),
                    'waste'          => (int) ($rows ? $rows->sum('total_wasted') : ($made->waste ?? 0)),
                    'adjustment'     => (int) ($rows?->sum('adjustment') ?? 0),
                    'compensation'   => (int) ($rows?->sum('compensation') ?? 0),
                ];
            })
            ->sortBy('menuName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Angka produksi per menu di outlet + hari laporan. Filternya sama dengan
     * ProductionItemService::getProductionMenuDetail (`whereDate(produced_at)`),
     * sumber angka rincian Sales Input.
     */
    private function productionByMenu(string $outletId, string $date): Collection
    {
        return ProductionItem::query()
            ->where('outlet_id', $outletId)
            ->whereNotNull('menu_id')
            ->whereDate('produced_at', $date)
            ->groupBy('menu_id')
            ->selectRaw('
                menu_id,
                MIN(plate_color) as plate_color,
                MIN(produced_at) as first_produced_at,
                SUM(quantity) as produced,
                SUM(CASE WHEN sold_at IS NOT NULL THEN quantity ELSE 0 END) as sold,
                SUM(CASE WHEN wasted_at IS NOT NULL THEN quantity ELSE 0 END) as waste
            ')
            ->get()
            ->keyBy('menu_id');
    }
}
