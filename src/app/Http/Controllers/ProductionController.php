<?php

namespace App\Http\Controllers;

    use Illuminate\Http\Request; 
    use App\Http\Resources\Production\ProductionItemResource;
    use App\Http\Resources\Production\ProductionItemGroupResource;
    use App\Http\Resources\Production\WasteRecordResource;
    use App\Http\Resources\Production\ProductionMenuResource;

    use App\Http\Requests\Production\ProductionProduceRequest;
    use App\Http\Requests\Production\ProductionPlanRequest;
    use App\Http\Requests\Production\ProductionExpiredBulkChangeRequest;
    use App\Http\Requests\Production\WasteRecordRequest;
    use App\Http\Requests\Production\WasteIndexRequest;

    use App\Services\ProductionService;

    class ProductionController extends BaseApiController
    {
        public function __construct(
            protected ProductionService $service, 
        ) {}

        /*
        |--------------------------------------------------------------------------
        | GET STATS
        |--------------------------------------------------------------------------
        */
        public function stats(Request $request)
        {
            $data = $this->service->dashboard->stats($request->outletId);

            return $this->success($data);
        }

        /*
        |--------------------------------------------------------------------------
        | GET PLAN
        |--------------------------------------------------------------------------
        */
        public function plan(Request $request)
        {
            $data = $this->service->plan->getPlanFormatted(
                $request->outletId,
                $request->date
            );

            return $this->success($data);
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE PLAN
        |--------------------------------------------------------------------------
        */
        public function savePlan(ProductionPlanRequest $request)
        {
           $this->service->plan->upsertPlan(
                $request->outletId,
                $request->date,
                $request->plan
            );

            return $this->success(null, 'Plan saved');
        }

        /*
        |--------------------------------------------------------------------------
        | PRODUCE
        |--------------------------------------------------------------------------
        */
        public function produce(ProductionProduceRequest $request)
        {
            $items = $this->service->item->produce($request->validated());

            return $this->success(
                ProductionItemResource::collection($items)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CONVEYOR (PER BATCH PRODUKSI)
        |--------------------------------------------------------------------------
        */
        public function conveyorGrouped(Request $request)
        {
            $groups = $this->service->item->conveyorGrouped($request->outletId);

            return $this->success(
                ProductionItemGroupResource::collection($groups)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | EXPIRED ITEMS (PER BATCH PRODUKSI)
        |--------------------------------------------------------------------------
        */
        public function expiredGrouped(Request $request)
        {
            $groups = $this->service->item->expiredGrouped($request->outletId);

            return $this->success(
                ProductionItemGroupResource::collection($groups)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE EXPIRED (SATU BATCH SEKALIGUS)
        |--------------------------------------------------------------------------
        | Pencatatan waste_records ada di dalam service, bukan di sini: hanya
        | service yang tahu piring mana yang benar-benar berhasil diklaim.
        |
        | Jalur per-piring yang dulu ada di sini melewatkan itu — ia memanggil
        | recordFromItems() tanpa melihat apakah piringnya benar-benar berubah,
        | jadi piring yang sudah ditutup tablet lain tetap dapat baris waste.
        */
        public function updateExpiredBulk(ProductionExpiredBulkChangeRequest $request)
        {
            $result = $this->service->item->updateExpiredBulk(
                $request->input('itemIds'),
                $request->input('status'),
                $request->input('notes')
            );

            return $this->success(
                $result,
                "{$result['updated']} plate diperbarui, {$result['skipped']} dilewati"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Mark as Sold
        |--------------------------------------------------------------------------
        */
        public function markSold(Request $request)
        {
            $this->service->item->markSold($request->itemIds);

            return $this->success(null, 'Items marked as sold');
        }
 
        /*
        |--------------------------------------------------------------------------
        | Mark as Waste
        |--------------------------------------------------------------------------
        */
        public function markWaste(Request $request)
        {
            $this->service->item->markWaste($request->itemIds);

            return $this->success(null, 'Items marked as waste');
        }

        /*
        |--------------------------------------------------------------------------
        | Close Day
        |--------------------------------------------------------------------------
        | Sisa plate hari ini yang belum difinalisasi ditandai terjual. Operator
        | hanya menandai waste satu per satu; sisanya dianggap laku saat tutup hari.
        */
        public function closeDay(Request $request)
        {
            $request->validate([
                'outletId' => ['required', 'uuid', 'exists:outlets,id'],
            ]);

            $closed = $this->service->item->closeDaySold($request->outletId);

            return $this->success(
                ['closed' => $closed],
                "{$closed} plate ditandai terjual"
            );
        }


        /*
        |--------------------------------------------------------------------------
        | WASTE CREATE
        |--------------------------------------------------------------------------
        */
        public function wasteStore(WasteRecordRequest $request)
        {
            $waste = $this->service->wasteRecord->recordFromItems(
                $request->getItemIds(),
                $request->reason
            );  
            return $this->success(
                WasteRecordResource::collection($waste)
            );
        }


        /*
        |--------------------------------------------------------------------------
        | WASTE LIST
        |--------------------------------------------------------------------------
        */
        public function wasteIndex(WasteIndexRequest $request)
        {
            $data = $this->service->wasteRecord->getByDateRange(
                $request->outletId,
                $request->startDate,
                $request->endDate
            );

            return $this->success(
                WasteRecordResource::collection($data)
            );
        }

        public function productionMenuDetail(Request $request)
        {
             $request->validate([
                    'outletId' => 'required|uuid',
                    'date' => 'required|date',
                    'plateColorId' => 'required|uuid',
                ]);
                
            $data = $this->service->item->getProductionMenuDetail(
                $request->outletId,
                $request->date,
                $request->plateColorId
            );


            return $this->resource(ProductionMenuResource::collection($data));
        }

         /*

        /*
        |--------------------------------------------------------------------------
        | HELPER
        |--------------------------------------------------------------------------
        */
       private function transformPlanItems(array $row): array
        {
            $items = [];

            foreach ($row as $key => $value) {

                if ($key === 'timeSlot') continue;

                $items[] = [
                    'plate_color' => $key,
                    'qty'         => $value,
                ];
            }

            return $items;
        }

        public function productionList(Request $request)
        {
            $request->validate([
                'outletId' => 'required|uuid',
                'date' => 'required|date',
            ]);

            $data = $this->service->item->getProductionList(
                $request->outletId,
                $request->date
            ); 
            
            return $this->resource(ProductionItemResource::collection($data));
        }
    }
