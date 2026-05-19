<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;
use App\Models\ProductionItem;
use Illuminate\Support\Collection;

class SalesClosingReportResource extends BaseResource
{
    public function toArray($request): array
    {
        $items = $this->items ?? collect();
        $entries = $this->entries();
        $summary = $this->summary($entries);

        $totalProduced = (int) $items->sum(function ($item) {
            return (int) $item->production_sold + (int) $item->production_waste;
        });
        $totalWaste = (int) $items->sum('production_waste');

        return [
            'id' => $this->id,
            'outletId' => $this->outlet_id,
            'outletName' => $this->outlet?->name,
            'date' => optional($this->date)->format('Y-m-d'),
            'status' => $this->status ?? 'draft',
            'totalProduced' => $totalProduced,
            'totalSold' => (int) $items->sum('production_sold'),
            'totalWaste' => $totalWaste,
            'totalPosSold' => (int) $items->sum('pos_sold'),
            'totalAdjustment' => (int) $items->sum('adjustment'),
            'totalCompensation' => (int) $items->sum('compensation'),
            'totalCompensationValue' => (float) $items->sum(function ($item) {
                return (int) $item->compensation * (float) ($item->plateColor?->price ?? 0);
            }),
            'wastePercentage' => $totalProduced > 0
                ? round(($totalWaste / $totalProduced) * 100, 2)
                : 0,
            'kitchenLeader' => null,
            'operationLeader' => null,
            'wastePhotoUrls' => [],
            'notes' => null,
            'entries' => $entries->values()->all(),
            'summary' => $summary,
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at?->format('Y-m-d H:i:s'),
            'submittedAt' => null,
            'submittedBy' => null,
        ];
    }

    private function entries(): Collection
    {
        $productionByMenu = $this->productionByMenu();
        $detailsByMenu = $this->detailsByMenu();

        return $productionByMenu->keys()
            ->merge($detailsByMenu->keys())
            ->filter()
            ->unique()
            ->values()
            ->map(function ($menuId) use ($productionByMenu, $detailsByMenu) {
                return $this->entry($menuId, $productionByMenu, $detailsByMenu);
            });
    }

    private function productionByMenu(): Collection
    {
        return ProductionItem::query()
            ->selectRaw("
                menu_id,
                MIN(plate_color) as plate_color,
                SUM(quantity) as produced,
                SUM(CASE WHEN sold_at IS NOT NULL THEN quantity ELSE 0 END) as sold,
                SUM(CASE WHEN wasted_at IS NOT NULL THEN quantity ELSE 0 END) as waste
            ")
            ->where('outlet_id', $this->outlet_id)
            ->whereDate('produced_at', optional($this->date)->format('Y-m-d'))
            ->groupBy('menu_id')
            ->with(['menu.plateColor', 'plateColor'])
            ->get()
            ->keyBy('menu_id');
    }

    private function detailsByMenu(): Collection
    {
        return ($this->items ?? collect())
            ->flatMap(function ($item) {
                return ($item->details ?? collect())->map(function ($detail) use ($item) {
                    return [
                        'detail' => $detail,
                        'salesItem' => $item,
                    ];
                });
            })
            ->groupBy(function ($row) {
                return $row['detail']->menu_id;
            });
    }

    private function entry(string $menuId, Collection $productionByMenu, Collection $detailsByMenu): array
    {
        $production = $productionByMenu->get($menuId);
        $detailRows = $detailsByMenu->get($menuId, collect());
        $firstDetailRow = $detailRows->first();
        $detail = $firstDetailRow['detail'] ?? null;
        $salesItem = $firstDetailRow['salesItem'] ?? null;
        $menu = $production?->menu ?? $detail?->menu;
        $plateColor = $production?->plateColor
            ?? $menu?->plateColor
            ?? $salesItem?->plateColor;

        $produced = (int) ($production?->produced ?? 0);
        $sold = (int) ($production?->sold ?? 0);
        $waste = (int) ($production?->waste ?? 0);
        $posSold = $sold;
        $adjustment = (int) $detailRows->sum(fn ($row) => (int) $row['detail']->adjustment);
        $compensation = (int) $detailRows->sum(fn ($row) => (int) $row['detail']->compensation);
        $sellingPrice = (int) ($menu?->price ?? $plateColor?->price ?? 0);

        return [
            'id' => $menuId,
            'menuId' => $menuId,
            'menuCode' => $menu?->code,
            'menuName' => $menu?->menuname ?? $detail?->menu_name,
            'categoryName' => null,
            'plateColorId' => $plateColor?->id,
            'plateColorName' => $plateColor?->platename,
            'plateColorCode' => $this->plateColorCode($plateColor?->platename),
            'plateColor' => $plateColor ? [
                'id' => $plateColor->id,
                'name' => $plateColor->platename,
                'code' => $this->plateColorCode($plateColor->platename),
            ] : null,
            'sellingPrice' => $sellingPrice,
            'produced' => $produced,
            'sold' => $sold,
            'waste' => $waste,
            'posSold' => $posSold,
            'adjustment' => $adjustment,
            'compensation' => $compensation,
            'compensationReason' => null,
            'selisih' => $posSold - ($sold + $adjustment + $compensation),
            'revenue' => $posSold * $sellingPrice,
        ];
    }

    private function summary(Collection $entries): array
    {
        $totalRevenue = (int) $entries->sum('revenue');
        $totalPosSold = (int) $entries->sum('posSold');
        $topSellingEntry = $entries->sortByDesc('sold')->first();

        return [
            'totalRevenue' => $totalRevenue,
            'averageSellingPrice' => $totalPosSold > 0
                ? (int) ($totalRevenue / $totalPosSold)
                : 0,
            'topSellingMenu' => $topSellingEntry['menuName'] ?? null,
            'topSellingQty' => $topSellingEntry['sold'] ?? 0,
        ];
    }

    private function plateColorCode(?string $name): ?string
    {
        return $name ? strtoupper(str_replace(' ', '_', $name)) : null;
    }
}
