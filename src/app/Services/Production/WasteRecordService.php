<?php

namespace App\Services\Production;

use App\Models\WasteRecord; 
use App\Models\ProductionItem;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\BaseService;

class WasteRecordService extends BaseService
{
    protected string $model = WasteRecord::class;

    protected array $searchable = ['outlet_id', 'plate_color'];
    protected array $sortable = ['recorded_at', 'created_at'];

    /*
    |--------------------------------------------------------------------------
    | RECORD WASTE
    |--------------------------------------------------------------------------
    */
    /*
    public function record(array $data)
    {
        return DB::transaction(function () use ($data) {

            $menu = Menu::findOrFail($data['menuId']); 

            return $this->create([
                'menu_id' => $menu->id,
                'outlet_id' => $data['outletId'],
                'plate_color' => $menu->plate_color,
                'quantity' => $data['quantity'],
                'reason' => $data['reason'],
                'recorded_at' => now(),
            ]);
        });
    }*/ 
    public function recordFromItems(array $itemIds, string $reason)
    {
        return DB::transaction(function () use ($itemIds, $reason) {
   
            $items = ProductionItem::whereIn('id', $itemIds)->get();

            $wasteRecords = collect();

            foreach ($items as $item) {
  
                $waste = $this->create([
                    'production_item_id' => $item->id,
                    'menu_id' => $item->menu_id,
                    'plate_color' => $item->plate_color,
                    'quantity' => 1,
                    'reason' => $reason,
                    'recorded_at' => now(),
                    'outlet_id' => $item->outlet_id,
                ]);
                $wasteRecords->push($waste);    
            }
            
            return $wasteRecords;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GET BY DATE RANGE
    |--------------------------------------------------------------------------
    */
    public function getByDateRange(string $outletId, string $startDate, string $endDate)
    {
        return $this->query()
            ->where('outlet_id', $outletId)
            ->whereBetween('recorded_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ])
            ->get();
    }
}