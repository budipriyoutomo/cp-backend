<?php

namespace App\Services\Production;

use App\Exceptions\BusinessRuleException;
use App\Models\PlateColors;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Services\BaseAggregateService;
use App\Services\Concerns\ResolvesOutletBrand;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ProductionPlanService extends BaseAggregateService
{
    use ResolvesOutletBrand;

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
        $this->assertPlateColorsBelongToOutletBrand($outletId, $plans);

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

    /**
     * Target produksi ditulis per warna piring, dan warna adalah unit harga.
     * Plan yang menyebut warna brand lain menghasilkan target untuk piring yang
     * tidak akan pernah dibuat di outlet ini — dan angkanya ikut ke dashboard.
     *
     * Dicek sebelum transaksi dibuka: tidak ada gunanya menulis separuh plan
     * lalu membatalkannya.
     */
    private function assertPlateColorsBelongToOutletBrand(string $outletId, array $plans): void
    {
        $outletBrandId = $this->brandIdForOutlet($outletId);

        if ($outletBrandId === null) {
            return;
        }

        $plateColorIds = collect($plans)
            ->flatMap(fn ($plan) => collect($plan['items'] ?? [])->pluck('plateColorId'))
            ->filter()
            ->unique()
            ->values();

        if ($plateColorIds->isEmpty()) {
            return;
        }

        // Warna ber-brand_id NULL sengaja lolos — sama seperti di POSService.
        $foreign = PlateColors::whereIn('id', $plateColorIds)
            ->whereNotNull('brand_id')
            ->where('brand_id', '!=', $outletBrandId)
            ->pluck('platename');

        if ($foreign->isNotEmpty()) {
            throw new BusinessRuleException(
                'Plan memuat warna piring dari brand lain: ' . $foreign->implode(', ') . '.'
            );
        }
    }
}