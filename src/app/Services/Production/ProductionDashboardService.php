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
                // Carbon is mutable: the old whereBetween([$today->startOfDay(),
                // $today->endOfDay()]) passed the same instance twice, so both
                // bounds ended up at 23:59:59 and the target was always 0.
                $q->where('outlet_id', $outletId)
                ->whereDate('date', $today);
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
            
        // Attribute waste by wasted_at, mirroring how sold uses sold_at. Using
        // expires_at drifted from the reports for carry-over plates, whose
        // wasted_at is deliberately set back to their production day.
        $waste = ProductionItem::where('outlet_id', $outletId)
            ->where('final_status', 'waste')
            ->whereDate('wasted_at', $today)
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