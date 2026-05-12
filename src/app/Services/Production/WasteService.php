<?php

namespace App\Services\Production;

use App\Models\ProductionItem;
use App\Models\WasteRecord;
use Illuminate\Support\Facades\DB;

class WasteService
{
    /**
     * Get waste list
     */
    public function getAll(array $filters = [])
    {
        $query = WasteRecord::query()
            ->with([
                'menu:id,menuname',
                'outlet:id,name,code',
                'plateColor:id,platename',
            ]);

        if (!empty($filters['outletId'])) {
            $query->where('outlet_id', $filters['outletId']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('recorded_at', $filters['date']);
        }

        if (!empty($filters['plateColorId'])) {
            $query->where('plate_color', $filters['plateColorId']);
        }

        return $query
            ->latest('recorded_at')
            ->get();
    }

    /**
     * Get waste summary
     */
    public function getSummary(array $filters = [])
    {
        $query = WasteRecord::query();

        if (!empty($filters['outletId'])) {
            $query->where('outlet_id', $filters['outletId']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('recorded_at', $filters['date']);
        }

        $totalWaste = (int) $query->sum('quantity');

        $productionQuery = ProductionItem::query();

        if (!empty($filters['outletId'])) {
            $productionQuery->where('outlet_id', $filters['outletId']);
        }

        if (!empty($filters['date'])) {
            $productionQuery->whereDate('produced_at', $filters['date']);
        }

        $productionByPlateColor = $productionQuery
            ->select(
                'plate_color',
                DB::raw('SUM(quantity) as production_count')
            )
            ->groupBy('plate_color')
            ->pluck('production_count', 'plate_color');

        $totalProduction = (int) $productionByPlateColor->sum();

        $plateColors = $query
            ->select(
                'plate_color',
                DB::raw('SUM(quantity) as waste_count')
            )
            ->groupBy('plate_color')
            ->get();

        return [
            'totalWaste' => $totalWaste,

            'totalProduction' => $totalProduction,

            'wastePercentage' => $totalProduction > 0
                ? round(($totalWaste / $totalProduction) * 100, 2)
                : 0,

            'byPlateColor' => $plateColors->map(function ($item) {
                return [
                    'plateColorId' => $item->plate_color,
                    'plateColorName' => ucfirst($item->plate_color),
                    'wasteCount' => (int) $item->waste_count,
                    'productionCount' => (int) ($productionByPlateColor[$item->plate_color] ?? 0),
                ];
            }),
        ];
    }

    /**
     * Get detail by id
     */
    public function getById(WasteRecord $waste)
    {
        return $waste->load([
            'menu:id,menuname',
            'outlet:id,name,code',
            'plateColor:id,platename',
        ]);
    }
}
