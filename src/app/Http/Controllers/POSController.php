<?php

namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Collection;
    use App\Http\Resources\POS\POSComparisonResource;  

    use App\Services\POSService;
    use App\Services\Production\ProductionItemService;



    class POSController extends BaseApiController
    {
        public function __construct(
            protected POSService $service, 
            protected ProductionItemService $productionItemService, 
        ) {}
         
        /*
        |--------------------------------------------------------------------------
        | GET POS DATA FOR Closing
        |--------------------------------------------------------------------------
        */

        public function getposData(Request $request)
        { 
            $dataPOS = $this->service->getPosDataForClosing(
                $request->outletId,
                $request->date
            );

            $dataProductionItems = $this->productionItemService->getSoldItem(
                $request->outletId,
                $request->date
            ); 

            $comparison = $this->buildPlateColorComparison(
                $dataPOS,
                $dataProductionItems
            );

            return response()->json([
                'status' => true,
                'message' => 'Success',

                'data' => POSComparisonResource::collection($comparison) 
 
            ]); 
        }
        private function buildPlateColorComparison($posData, $productionData)
        {
            // ✅ POS tetap sama
            $pos = collect($posData)
                ->groupBy('plate_color_id')
                ->map(function ($items) {
                    $first = $items->first();

                    return [
                        'plateColorId' => $first->plate_color_id,
                        'plateColorName' => $first->plateColor?->platename ?? 'Unknown',
                        'posSold' => (int) $items->sum('sold'),
                    ];
                });

            // ✅ Production sudah aggregated dari DB → tidak perlu group lagi
            $production = collect($productionData)
                ->keyBy('plate_color_id')
                ->map(function ($item) {
                    return [
                        'plateColorId' => $item->plate_color_id,
                        'plateColorName' => $item->plate_color_name ?? 'Unknown',
                        'productionSold' => (int) $item->quantity,
                    ];
                });

            // ✅ gabungkan semua key
            $keys = $pos->keys()
                ->merge($production->keys())
                ->filter()
                ->unique();

            return $keys->map(function ($key) use ($pos, $production) {

                $posItem = $pos->get($key);
                $prodItem = $production->get($key);

                $posSold = $posItem['posSold'] ?? 0;
                $productionSold = $prodItem['productionSold'] ?? 0;

                return [
                    'plateColorId' => $key,
                    'plateColorName' => $posItem['plateColorName']
                        ?? $prodItem['plateColorName']
                        ?? 'Unknown',

                    'posSold' => $posSold,
                    'productionSold' => $productionSold,
                    'selisih' => $posSold - $productionSold,
                ];
            })->values();
        }
        public function subscribePOSData(Request $request)
        {
            
        }

    }