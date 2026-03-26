<?php

namespace App\Services\Production;

use App\Models\ProductionItem;
use App\Models\ProductionPlanItem;

class ProductionDashboardService
{
    public function stats(string $outletId)
    {
        $today = today();

        $plateColors = ProductionItem::select('plate_color')
            ->distinct()
            ->pluck('plate_color');

        return $plateColors->map(function ($color) use ($outletId, $today) {

            return [
                'plateColor' => $color,

                'targetToday' => ProductionPlanItem::whereHas('plan', function ($q) use ($outletId, $today) {
                        $q->where('outlet_id', $outletId)
                          ->whereDate('date', $today);
                    })
                    ->where('plate_color', $color)
                    ->sum('qty'),

                'produced' => ProductionItem::where('outlet_id', $outletId)
                    ->where('plate_color', $color)
                    ->whereDate('produced_at', $today)
                    ->count(),

                'sold' => 0,

                'expiringSoon' => ProductionItem::where('outlet_id', $outletId)
                    ->where('plate_color', $color)
                    ->where('status', 'warning')
                    ->count(),

                'outletId' => $outletId
            ];
        });
    }
}