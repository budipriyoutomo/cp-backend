<?php

namespace App\Services\Reports;

use App\Models\SalesHeader;

class DailySummaryService
{
    public function get(string $outletId, string $date): array
    {
        /*
        |--------------------------------------------------------------------------
        | SALES HEADER
        |--------------------------------------------------------------------------
        */
        $header = SalesHeader::query()
            ->with([
                'outlet:id,name',
                'items.plateColor:id,platename',
            ])
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | ITEMS
        |--------------------------------------------------------------------------
        */
        $items = collect(
            $header?->items ?? []
        )->map(function ($item) {

            return [
                'plateColorId' => $item->plateColor?->id,
                'plateColorName' => $item->plateColor?->platename,

                'posSold' => $item->pos_sold,
                'productionSold' => $item->production_sold,
                'productionWaste' => $item->production_waste,

                'adjustment' => $item->adjustment,
                'compensation' => $item->compensation,

                'selisih' => $item->selisih,
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */
        return [
            'outletId' => $header?->outlet_id,
            'outletName' => $header?->outlet?->name,
            'date' => $date,

            'totalPOS' => $items->sum('posSold'),
            'totalProduction' => $items->sum('productionSold'),
            'totalWaste' => $items->sum('productionWaste'),

            'totalAdjustment' => $items->sum('adjustment'),
            'totalCompensation' => $items->sum('compensation'),

            'totalSelisih' => $items->sum('selisih'),

            'items' => $items->values(),
        ];
    }
}