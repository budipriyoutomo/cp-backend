<?php

namespace App\Services\Production;

use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Services\BaseAggregateService;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

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
            ->with(['items.plateColor'])
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->get();

        return $plans->map(function ($plan) {

            $row = [
                'timeSlot' => $plan->time_slot,
            ];

            foreach ($plan->items as $item) {
                $colorName = strtolower($item->plateColor->platename);
                $row[$colorName] = $item->qty;
            }

            return $row;
        });
    }


    public function upsertPlan(string $outletId, string $date, array $plans)
    {
        DB::transaction(function () use ($outletId, $date, $plans) {

            $now = now();

            $planRows = [];

            foreach ($plans as $plan) {

                $planRows[] = [
                    'id'         => (string) Str::uuid(),
                    'date'       => $date,
                    'outlet_id'  => $outletId,
                    'time_slot'  => $plan['timeSlot'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            //  UPSERT parent
            ProductionPlan::upsert(
                $planRows,
                ['outlet_id', 'date', 'time_slot'],
                ['updated_at']
            );

            // ambil mapping ID
            $existingPlans = ProductionPlan::query()
                ->where('outlet_id', $outletId)
                ->whereDate('date', $date)
                ->get()
                ->keyBy('time_slot');

            $finalItems = [];

            foreach ($plans as $plan) {

                $planId = $existingPlans[$plan['timeSlot']]->id;

                foreach ($plan['items'] as $item) {

                    $finalItems[] = [
                        'id'                  => (string) Str::uuid(),
                        'production_plan_id'  => $planId,
                        'plate_color'         => $item['plateColorId'],
                        'qty'                 => $item['qty'],
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }
            }

            // delete lama
            ProductionPlanItem::whereIn(
                'production_plan_id',
                $existingPlans->pluck('id')
            )->delete();

            // insert baru
            ProductionPlanItem::insert($finalItems);
        });
    }
}