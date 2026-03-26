<?php

namespace App\Http\Controllers;

    use Illuminate\Http\Request; 
    use App\Http\Resources\Production\ProductionItemResource;
    use App\Http\Resources\Production\WasteRecordResource;

    use App\Http\Requests\Production\ProductionProduceRequest;
    use App\Http\Requests\Production\ProductionPlanRequest;
    use App\Http\Requests\Production\ProductionRemoveExpiredRequest;
    use App\Http\Requests\Production\ProductionExpiredChangeRequest;
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
            $this->service->plan->create([
                'date' => $request->date,
                'outlet_id' => $request->outletId,
                'items' => $this->transformPlanItems($request->plan)
            ]);

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
        | CONVEYOR
        |--------------------------------------------------------------------------
        */
        public function conveyor(Request $request)
        {
            $items = $this->service->item->conveyor($request->outletId); 
            
            return $this->success(
                ProductionItemResource::collection($items)
            );
        } 

        /*
        |--------------------------------------------------------------------------
        | EXPIRED ITEMS
        |--------------------------------------------------------------------------
        */
        public function expired(Request $request)
        {
            $items = $this->service->item->expired($request->outletId); 
            
            return $this->success(
                ProductionItemResource::collection($items)
            );
            
        }

        /*
        |--------------------------------------------------------------------------
        | EXPIRED ITEMS
        |--------------------------------------------------------------------------
        */
        public function updateExpired(ProductionExpiredChangeRequest $request, string $id)
        {
            $this->service->item->updateExpired([
                'id'     => $id,
                'status' => $request->status,
                'notes'  => $request->notes,
            ]);

            if ($request->status === 'waste') {
                $this->service->waste->recordFromItems(
                    [$id],
                    $request->notes ?? 'Marked as waste from expired items'
                );
            }

            return $this->success(null, 'Expired item updated successfully');
        }


        /*
        |--------------------------------------------------------------------------
        | REMOVE EXPIRED
        |--------------------------------------------------------------------------
        */
        public function removeExpired(ProductionRemoveExpiredRequest $request)
        {
            $this->service->item->removeExpired($request->validated()['itemIds']);

            return $this->success(null, 'Expired items removed');
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
        | WASTE CREATE
        |--------------------------------------------------------------------------
        */
        public function wasteStore(WasteRecordRequest $request)
        {
            $waste = $this->service->waste->recordFromItems(
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
            $data = $this->service->waste->getByDateRange(
                $request->outletId,
                $request->startDate,
                $request->endDate
            );

            return $this->success(
                WasteRecordResource::collection($data)
            );
        }

        

        /*
        |--------------------------------------------------------------------------
        | HELPER
        |--------------------------------------------------------------------------
        */
        private function transformPlanItems(array $rows): array
        {
            $items = [];

            foreach ($rows as $row) {
                foreach ($row as $key => $value) {

                    if ($key === 'timeSlot') continue;

                    $items[] = [
                        'plate_color' => $key,
                        'qty' => $value,
                    ];
                }
            }

            return $items;
        }
    }