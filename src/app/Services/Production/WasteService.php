<?php

namespace App\Services\Production;

use App\Models\PlateColors;
use App\Models\ProductionItem;
use App\Models\WasteRecord;
use App\Support\Uuid;
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

        $wasteByColor = $query
            ->select(
                'plate_color',
                DB::raw('SUM(quantity) as waste_count')
            )
            ->groupBy('plate_color')
            ->get();

        // waste_records.plate_color holds a plate color UUID, so the name has to
        // come from the master table. Resolved in PHP rather than with a JOIN:
        // plate_colors.id is uuid while plate_color is varchar, and PostgreSQL
        // has no operator for that comparison. Same pattern as WasteAnalysisService.
        // Disaring dulu lewat Uuid::matches: kolom sumbernya varchar, jadi satu
        // baris warisan yang isinya nama warna (bukan uuid) cukup untuk membuat
        // `whereIn` di kolom uuid melempar 22P02 dan mematikan seluruh ringkasan.
        // Baris begitu tidak punya nama untuk diresolusi — fallback "Unknown"
        // di bawah sudah menanganinya.
        $plateColorNames = PlateColors::query()
            ->whereIn('id', $wasteByColor->pluck('plate_color')->filter(
                fn ($value) => Uuid::matches($value)
            )->all())
            ->pluck('platename', 'id');

        return [
            'totalWaste' => $totalWaste,

            'totalProduction' => $totalProduction,

            'wastePercentage' => $totalProduction > 0
                ? round(($totalWaste / $totalProduction) * 100, 2)
                : 0,

            'byPlateColor' => $wasteByColor->map(function ($item) use ($plateColorNames, $productionByPlateColor) {
                return [
                    'plateColorId' => $item->plate_color,
                    'plateColorName' => $plateColorNames[$item->plate_color] ?? 'Unknown',
                    'wasteCount' => (int) $item->waste_count,
                    'productionCount' => (int) ($productionByPlateColor[$item->plate_color] ?? 0),
                ];
            })->values(),
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
