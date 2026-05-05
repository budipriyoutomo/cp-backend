<?php

namespace App\Services;

use App\Models\POSData;
use App\Models\PlateColors;
use App\Models\Outlet;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class POSService
{
    public function __construct(
        public POSData $posData
    ) {}

    public function getPosDataForClosing($outletId, $date)
    {
        return $this->posData
            ->where('outlet_id', $outletId)
            ->where('date', $date)
            ->with('plateColor')
            ->get();
    }
    
    public function storeFromEvent(array $data)
    {
        return DB::transaction(function () use ($data) {

            // 🔥 normalize
            $plateName = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $data['platecolor'])));
            $plateName = preg_replace('/\s+/u', ' ', $plateName);
            $outletCode = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $data['outlet'])));
            $outletCode = preg_replace('/\s+/u', ' ', $outletCode);


            // 🔥 mapping plate color
            $plate = PlateColors::whereRaw('LOWER(TRIM(platename)) = ?', [$plateName])->first(); 

            if (!$plate) {
                throw new \Exception("Plate color not found: {$plateName}");
            }

            // 🔥 mapping outlet 
            $outlet = Outlet::whereRaw('LOWER(TRIM(code)) = ?', [$outletCode])->first(); 

            if (!$outlet) {
                throw new \Exception("Outlet not found: {$outletCode}");
            }

            // 🔥 cek existing (idempotent)
            $existing = $this->posData
                ->where('outlet_id', $outlet->id)
                ->where('plate_color_id', $plate->id)
                ->where('date', $data['date'])
                ->first();

            // 🔥 UPSERT MODE (lebih aman daripada skip)
            if ($existing) {
                $existing->update([
                    'sold' => $data['sold']
                ]);

                return [
                    'status' => 'updated',
                    'id' => $existing->id
                ];
            }

            // ✅ insert baru
            $pos = $this->posData->create([
                'id' => (string) Str::uuid(),
                'plate_color_id' => $plate->id,
                'outlet_id' => $outlet->id,
                'date' => $data['date'],
                'sold' => $data['sold'],
            ]);

            return [
                'status' => 'created',
                'id' => $pos->id
            ];
        });
    }
}