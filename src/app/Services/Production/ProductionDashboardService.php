<?php

namespace App\Services\Production;

use App\Models\PlateColors;
use App\Models\ProductionItem;
use App\Models\ProductionPlanItem; 

class ProductionDashboardService
{
    public function stats(string $outletId)
    {
        $today = today();

        // 🔥 ambil semua warna dari plan + production
        $plateColors = PlateColors::where('is_active', true)
            ->get(['platename', 'id']);

        $targets = ProductionPlanItem::whereHas('plan', function ($q) use ($outletId, $today) {
                $q->where('outlet_id', $outletId)
                ->whereBetween('date', [
                    $today->startOfDay(),
                    $today->endOfDay()
                ]);
            })
            ->selectRaw('plate_color, SUM(qty) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color'); 

        // 🔥 produced today
        $produced = ProductionItem::where('outlet_id', $outletId)
            ->whereDate('produced_at', $today)
            ->selectRaw('plate_color, COUNT(*) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');

        $expiring = ProductionItem::where('outlet_id', $outletId)
            ->where('belt_status', 'expired')
            ->whereNull('final_status')
            ->whereDate('expires_at', $today)
            ->selectRaw('plate_color, COUNT(*) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');

        $sold = ProductionItem::where('outlet_id', $outletId)
            ->where('final_status', 'sold')
            ->whereDate('sold_at', $today)
            ->selectRaw('plate_color, COUNT(*) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');
            
        $waste = ProductionItem::where('outlet_id', $outletId)
            ->where('final_status', 'waste')
            ->whereDate('expires_at', $today)
            ->selectRaw('plate_color, COUNT(*) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');

        return $plateColors->map(function ($color) use ($targets, $produced, $sold, $waste, $expiring, $outletId) {

            return [
                'plateColor'    => $color->platename,
                'targetToday'   => $targets[$color->id] ?? 0,
                'produced'      => $produced[$color->id] ?? 0,
                'sold'          => $sold[$color->id] ?? 0 ,
                'waste'         => $waste[$color->id] ?? 0,
                'expiringSoon'  => $expiring[$color->id] ?? 0,
                'outletId'      => $outletId,
            ];
        })->values();
    }
}