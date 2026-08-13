<?php

namespace App\Services\Production;

use App\Models\ProductionItem;
use App\Models\Menu;
use App\Models\WasteRecord;
use App\Exceptions\BusinessRuleException;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    /**
     * Read-only. `expires_at` is the source of truth, so the belt state is
     * filtered and rendered from it rather than from the stored `belt_status`.
     *
     * This used to run two mass UPDATEs before every read — and the kitchen
     * polls it every 30 seconds per tablet, so a GET was writing the whole
     * outlet's rows several times a minute. The stored column is now kept fresh
     * by `production:refresh-belt-status` instead; see Console\Kernel.
     */
    public function conveyor(string $outletId)
    {
        $items = $this->query()
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->whereDate('produced_at', today())
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        // Refresh the in-memory value only, so the response is accurate to the
        // second regardless of when the scheduler last ran. Nothing is saved.
        return $items->each->updateBeltStatus();
    }

    /*
    |--------------------------------------------------------------------------
    | GET Expired Items
    |--------------------------------------------------------------------------
    */
    /**
     * Read-only, same reasoning as conveyor(): filtered on expires_at rather
     * than on the stored belt_status, which may lag behind by up to a minute.
     */
    public function expired(string $outletId)
    {
        $items = $this->query()
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->where('expires_at', '<=', now())
            ->whereDate('produced_at', today())
            ->orderBy('expires_at')
            ->get();

        return $items->each->updateBeltStatus();
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE Expired Items
    |--------------------------------------------------------------------------
    */
    public function updateExpired(array $data) 
    {
        // Gate on expires_at, not on the stored belt_status: the column is
        // refreshed by a scheduled job and can lag by up to a minute, which
        // would otherwise reject a plate that really has expired.
        $item = ProductionItem::query()
            ->where('id', $data['id'])
            ->where('expires_at', '<=', now())
            ->whereNull('final_status')
            ->first();
            
        if (!$item) {
            Log::warning('Update expired skipped', [
                'id' => $data['id'],
                'reason' => 'Not found / already processed / invalid status'
            ]);

            return null;
        }

        // 🔒 Plate hanya boleh difinalisasi pada hari produksinya.
        if (!$item->produced_at || !$item->produced_at->isToday()) {
            throw new BusinessRuleException(
                'Plate dari hari sebelumnya tidak dapat ditandai sold/waste. '
                . 'Item sisa otomatis menjadi waste saat pergantian hari.'
            );
        }

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
        $this->assertWithinProductionDay($ids);

        return $this->query()
            ->whereIn('id', $ids)
            ->whereNull('final_status')
            ->whereDate('produced_at', today())
            ->update([
                'final_status' => 'sold',
                'sold_at' => now(),
            ]);
    }
    public function markWaste(array $ids)
    {
        $this->assertWithinProductionDay($ids);

        return $this->query()
            ->whereIn('id', $ids)
            ->whereNull('final_status')
            ->whereDate('produced_at', today())
            ->update([
                'final_status' => 'waste',
                'wasted_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | BUSINESS-DAY GUARD
    |--------------------------------------------------------------------------
    | Plate hanya boleh ditandai sold/waste pada hari produksinya (produced_at).
    | Mencegah data kemarin ter-update menjadi sold/waste hari ini.
    */
    private function assertWithinProductionDay(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $stale = ProductionItem::whereIn('id', $ids)
            ->whereDate('produced_at', '!=', today()->toDateString())
            ->count();

        if ($stale > 0) {
            throw new BusinessRuleException(
                "Tidak bisa menandai {$stale} plate dari hari sebelumnya. "
                . 'Plate hanya dapat ditandai sold/waste pada hari produksinya.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UNRESOLVED COUNT (gate untuk Get Data POS)
    |--------------------------------------------------------------------------
    | Jumlah plate pada tanggal tertentu yang belum ditandai sold/waste.
    */
    public function countUnresolved(string $outletId, string $date): int
    {
        $date = \Carbon\Carbon::parse($date)->toDateString();

        return ProductionItem::where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->whereDate('produced_at', $date)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO-WASTE CARRY-OVER (saat pergantian hari)
    |--------------------------------------------------------------------------
    | Plate hari-hari sebelumnya yang belum diselesaikan (final_status NULL)
    | otomatis menjadi waste, diatribusikan ke hari produksinya — bukan hari ini.
    */
    public function autoWasteCarryOver(?string $outletId = null): int
    {
        $query = ProductionItem::query()
            ->whereNull('final_status')
            ->whereDate('produced_at', '<', today());

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($items) {
            $reason = 'Auto-waste: plate tidak diselesaikan pada hari produksi';

            foreach ($items as $item) {
                $item->update([
                    'final_status' => 'waste',
                    'belt_status'  => 'expired',
                    // 🔑 atribusi ke hari produksi, bukan now()
                    'wasted_at'    => $item->produced_at,
                    'notes'        => $item->notes ?: $reason,
                ]);

                WasteRecord::create([
                    'production_item_id' => $item->id,
                    'menu_id'            => $item->menu_id,
                    'plate_color'        => $item->plate_color,
                    'quantity'           => $item->quantity ?? 1,
                    'reason'             => $reason,
                    'recorded_at'        => $item->produced_at,
                    'outlet_id'          => $item->outlet_id,
                ]);
            }

            return $items->count();
        });
    }
    public function getSoldItem($outletId, $date)
    {
        return $this->query() 
            ->leftJoin('plate_colors', DB::raw('plate_colors.id::text'), '=', 'production_items.plate_color')
            ->where('production_items.outlet_id', $outletId)
            ->whereDate('production_items.sold_at', $date)
            ->groupBy('plate_colors.id', 'plate_colors.platename')
            ->get([
                'plate_colors.id as plate_color_id',
                'plate_colors.platename as plate_color_name',
                DB::raw('SUM(production_items.quantity) as quantity'),
            ]);
    }

    public function getWasteItem($outletId, $date)
    {
        return $this->query() 
            ->leftJoin('plate_colors', DB::raw('plate_colors.id::text'), '=', 'production_items.plate_color')
            ->where('production_items.outlet_id', $outletId)
            ->whereDate('production_items.wasted_at', $date)
            ->groupBy('plate_colors.id', 'plate_colors.platename')
            ->get([
                'plate_colors.id as plate_color_id',
                'plate_colors.platename as plate_color_name',
                DB::raw('SUM(production_items.quantity) as quantity'),
            ]);
    }
 
    public function getProductionMenuDetail(
        string $outletId,
        string $date,
        string $plateColorId
    ) {

    $date = \Carbon\Carbon::parse($date)->toDateString();

    return ProductionItem::selectRaw("
            menu_id,
            sum(quantity) as total_produced,
            SUM(CASE WHEN sold_at IS NOT NULL THEN quantity ELSE 0 END) as total_sold,
            SUM(CASE WHEN wasted_at IS NOT NULL THEN quantity ELSE 0 END) as total_wasted
        ")
        ->where('outlet_id', $outletId)
        ->whereDate('produced_at', $date)
        ->where('plate_color', $plateColorId)
        ->groupBy('menu_id')
        ->with('menu')
        ->get();
    } 
 
    public function getProductionList(
        string $outletId,
        string $date
    ) {
    $date = \Carbon\Carbon::parse($date)->toDateString();
    return ProductionItem::where('outlet_id', $outletId)
        ->whereDate('produced_at', $date)
        ->with('menu')
        ->get();
    }
}