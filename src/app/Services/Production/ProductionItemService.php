<?php

namespace App\Services\Production;

use App\Models\ProductionItem;
use App\Models\Menu;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

class ProductionItemService extends BaseService
{
    protected string $model = ProductionItem::class;

    protected array $relations = ['menu'];
    protected array $searchable = ['plate_color', 'status', 'outlet_id'];
    protected array $sortable = ['produced_at', 'created_at'];

    /*
    |--------------------------------------------------------------------------
    | PRODUCE ITEM
    |--------------------------------------------------------------------------
    */
    public function produce(array $data)
    {   
        return DB::transaction(function () use ($data) {
 
            $menu = Menu::with('plateColor')->findOrFail($data['menuId']);

            // ✅ guard (lebih aman & jelas)
            if (!$menu->plateColor?->id) {
                throw new \Exception("Menu belum memiliki plate color yang valid");
            }

            $now = now();
            $expiresAt = $now->copy()->addMinutes($menu->shelf_life ?? 60);

            // ✅ build payload sekali (lebih clean)
            $payload = [
                'menu_id'     => $menu->id,
                'outlet_id'   => $data['outletId'],
                'plate_color' => $menu->plateColor->id, 
                'quantity'    => 1,
                'produced_at' => $now,
                'expires_at'  => $expiresAt,
                'status'      => 'fresh',
            ];

            // ✅ lebih clean pakai collection
            $items = collect()
                ->times($data['quantity'], function () use ($payload) {
                    return $this->create($payload);
                });

            return $items->values();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GET CONVEYOR
    |--------------------------------------------------------------------------
    */
    public function conveyor(string $outletId)
    {
         
        DB::table('production_items')
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->where('expires_at', '<=', now())
            ->update(['belt_status' => 'expired']);
 
        DB::table('production_items')
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->where('expires_at', '<=', now()->addMinutes(15))
            ->where('expires_at', '>', now())
            ->update(['belt_status' => 'warning']);

        return $this->query()
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')  
            ->whereIn('belt_status', ['fresh', 'warning'])
            ->orderBy('expires_at')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | GET Expired Items
    |--------------------------------------------------------------------------
    */
    public function expired(string $outletId)
    {
        return $this->query()
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->where('belt_status', 'expired')
            ->whereDate('expires_at', now()->toDateString())
            ->orderBy('expires_at')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE Expired Items
    |--------------------------------------------------------------------------
    */
    public function updateExpired(array $data) 
    {
        $item = ProductionItem::query()
            ->where('id', $data['id'])
            ->where('belt_status', 'expired')
            ->whereNull('final_status')
            ->first(); 

        $updateData = [
            'final_status' => $data['status'],
            'notes'        => $data['notes'],
        ];

         if ($data['status'] === 'sold') {
            $updateData['sold_at'] = now();
        }

        if ($data['status'] === 'waste') {
            $updateData['wasted_at'] = now();
        }

        $item->update($updateData);

        return $item;
        
    }

    /*
    |--------------------------------------------------------------------------
    | MARK SOLD
    |--------------------------------------------------------------------------
    */
    public function markSold(array $ids)
    {
        return $this->query()
            ->whereIn('id', $ids)
            ->update([
                'final_status' => 'sold',
                'sold_at' => now(),
            ]);
    }
    public function markWaste(array $ids)
    {
        return $this->query()
            ->whereIn('id', $ids)
            ->update([
                'final_status' => 'waste',
                'wasted_at' => now(),
            ]);
    }
    /*
    |--------------------------------------------------------------------------
    | REMOVE EXPIRED
    |--------------------------------------------------------------------------
    */
    public function removeExpired(array $ids)
    {
        return $this->query()
            ->whereIn('id', $ids)
            ->update(['status' => 'expired']);
    }
}