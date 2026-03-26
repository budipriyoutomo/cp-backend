<?php

namespace App\Services\Production;

use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Services\BaseAggregateService;

class ProductionPlanService extends BaseAggregateService
{
    protected string $model = ProductionPlan::class;
    protected string $itemModel = ProductionPlanItem::class;
    protected string $itemForeignKey = 'production_plan_id';

    protected array $relations = ['items'];
    protected array $searchable = ['date', 'time_slot', 'outlet_id'];
    protected array $sortable = ['date', 'time_slot', 'created_at'];

    /*
    |--------------------------------------------------------------------------
    | CUSTOM: GET PLAN (FORMAT FRONTEND)
    |--------------------------------------------------------------------------
    */
    public function getPlanFormatted(string $outletId, string $date)
    {
        $plans = $this->query()
            ->with('items')
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->get();

        return $plans->map(function ($plan) {

            $row = [
                'timeSlot' => $plan->time_slot,
            ];

            foreach ($plan->items as $item) {
                $row[$item->plate_color] = $item->qty;
            }

            return $row;
        });
    }
}