<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;
use App\Services\ClosingReport\ClosingReportMenuLines;
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

    /**
     * Baris per menu dari ClosingReportMenuLines — perhitungan yang sama
     * dengan payload BI, jadi angka di layar = angka yang dikirim. Jangan
     * hitung ulang di sini.
     */
    private function entries(): Collection
    {
        return app(ClosingReportMenuLines::class)
            ->for($this->id, $this->outlet_id, optional($this->date)->format('Y-m-d'))
            ->map(fn (array $line) => $this->entry($line));
    }

    private function entry(array $line): array
    {
        $plateColor = $line['plateColor'];
        $sellingPrice = $line['unitPrice'];
        $sold = $line['sold'];
        $posSold = $sold;
        $adjustment = $line['adjustment'];
        $compensation = $line['compensation'];

        return [
            'id' => $line['menuId'],
            'menuId' => $line['menuId'],
            'menuCode' => $line['menuCode'],
            'menuName' => $line['menuName'],
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
            'produced' => $line['produced'],
            'sold' => $sold,
            'waste' => $line['waste'],
            'posSold' => $posSold,
            'adjustment' => $adjustment,
            'compensation' => $compensation,
            'compensationReason' => null,
            'selisih' => $posSold - ($sold + $adjustment + $compensation),
            'revenue' => round($posSold * $sellingPrice, 2),
        ];
    }

    private function summary(Collection $entries): array
    {
        $totalRevenue = round((float) $entries->sum('revenue'), 2);
        $totalPosSold = (int) $entries->sum('posSold');
        $topSellingEntry = $entries->sortByDesc('sold')->first();

        return [
            'totalRevenue' => $totalRevenue,
            'averageSellingPrice' => $totalPosSold > 0
                ? round($totalRevenue / $totalPosSold, 2)
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
