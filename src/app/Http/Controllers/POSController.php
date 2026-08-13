<?php

namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use App\Http\Resources\POS\POSComparisonResource;

    use App\Services\POSService;
    use App\Services\Production\ProductionItemService;
    use App\Exceptions\BusinessRuleException;



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
            // 🧹 Bereskan plate sisa hari sebelumnya yang belum diselesaikan.
            // Otomatis menjadi waste, diatribusikan ke hari produksinya.
            $this->productionItemService->autoWasteCarryOver($request->outletId);

            // 🚧 Blokir jika masih ada plate pada tanggal ini yang belum
            // ditandai sold/waste — data POS tidak bisa direkonsiliasi.
            $pending = $this->productionItemService->countUnresolved(
                $request->outletId,
                $request->date
            );

            if ($pending > 0) {
                throw new BusinessRuleException(
                    "Masih ada {$pending} plate yang belum ditandai sold/waste untuk tanggal ini. "
                    . 'Selesaikan dulu semua plate di Conveyor sebelum mengambil data POS.'
                );
            }

            $dataPOS = $this->service->getPosDataForClosing(
                $request->outletId,
                $request->date
            );

            $dataSoldItems = $this->productionItemService->getSoldItem(
                $request->outletId,
                $request->date
            ); 

            $dataWasteItems = $this->productionItemService->getWasteItem(
                $request->outletId,
                $request->date
            ); 

            $comparison = $this->buildPlateColorComparison(
                $dataPOS,
                $dataSoldItems,
                $dataWasteItems
            );

            return response()->json([
                'status' => true,
                'message' => 'Success',

                'data' => POSComparisonResource::collection($comparison) 
 
            ]); 
        }

        
        private function buildPlateColorComparison($posData, $soldItems, $wasteItems)
        {
            // ✅ POS
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

            // ✅ Production Sold
            $sold = collect($soldItems)
                ->keyBy('plate_color_id')
                ->map(function ($item) {
                    return [
                        'plateColorId' => $item->plate_color_id,
                        'plateColorName' => $item->plate_color_name ?? 'Unknown',
                        'productionSold' => (int) $item->quantity,
                    ];
                });

            // ✅ Waste
            $waste = collect($wasteItems)
                ->keyBy('plate_color_id')
                ->map(function ($item) {
                    return [
                        'plateColorId' => $item->plate_color_id,
                        'plateColorName' => $item->plate_color_name ?? 'Unknown',
                        'waste' => (int) $item->quantity,
                    ];
                });

            // ✅ gabungkan semua key
            $keys = $pos->keys()
                ->merge($sold->keys())
                ->merge($waste->keys())
                ->filter()
                ->unique();

            return $keys->map(function ($key) use ($pos, $sold, $waste) {

                $posItem = $pos->get($key);
                $soldItem = $sold->get($key);
                $wasteItem = $waste->get($key);

                $posSold = $posItem['posSold'] ?? 0;
                $productionSold = $soldItem['productionSold'] ?? 0;
                $wasteQty = $wasteItem['waste'] ?? 0;

                return [
                    'plateColorId' => $key,
                    'plateColorName' => $posItem['plateColorName']
                        ?? $soldItem['plateColorName']
                        ?? $wasteItem['plateColorName']
                        ?? 'Unknown',

                    'posSold' => $posSold,
                    'productionSold' => $productionSold,
                    'productionWaste' => $wasteQty,
 
                    'selisih' => $posSold - $productionSold,
                ];
            })->values();
        }

    }