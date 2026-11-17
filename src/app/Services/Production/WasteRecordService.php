<?php

namespace App\Services\Production;

use App\Models\WasteRecord; 
use App\Models\ProductionItem;
use App\Models\User;
use App\Services\Concerns\AuthorizesProductionItems;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\BaseService;

class WasteRecordService extends BaseService
{
    use AuthorizesProductionItems;
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
    /**
     * `POST /production/waste`. Semua item harus milik outlet request dan user
     * harus boleh outlet itu (AuthorizesProductionItems).
     *
     * Hanya piring yang belum difinalisasi yang dicatat. Layar dapur memanggil
     * endpoint ini **sebelum** `mark-waste` (useConveyorGroups().wasteItems),
     * jadi piring yang sah selalu masih `final_status = NULL`. Piring yang
     * sudah sold/waste — ditutup tablet lain, atau request yang dikirim ulang
     * setelah mark-waste — dulu tetap mendapat waste record kedua, padahal
     * angka waste itulah yang direkonsiliasi dengan POS.
     *
     * Filter itu sengaja tidak ada di recordFromItems(): updateExpiredBulk()
     * memanggilnya untuk piring yang baru saja ia klaim sebagai waste.
     */
    public function recordForOutlet(User $actor, array $itemIds, string $reason, ?string $outletId = null)
    {
        $outletId = $this->authorizeItemsForOutlet($actor, $itemIds, $outletId);

        if ($outletId === null) {
            return collect();
        }

        $open = ProductionItem::whereIn('id', $itemIds)
            ->where('outlet_id', $outletId)
            ->whereNull('final_status')
            ->pluck('id')
            ->all();

        return $this->recordFromItems($open, $reason);
    }

    /**
     * Tanpa pemeriksaan outlet maupun status — untuk pemanggil internal yang
     * sudah mengklaim piringnya sendiri (ProductionItemService::updateExpiredBulk).
     * Endpoint memakai recordForOutlet().
     */
    public function recordFromItems(array $itemIds, string $reason)
    {
        // Snapshot reason + kategori dari teks yang dikirim layar dapur. Teks
        // yang tidak dikenal tetap dicatat, hanya tanpa kategori.
        $snapshot = app(WasteReasonResolver::class)->forText($reason);

        return DB::transaction(function () use ($itemIds, $reason, $snapshot) {
   
            $items = ProductionItem::whereIn('id', $itemIds)->get();

            $wasteRecords = collect();

            foreach ($items as $item) {
  
                $waste = $this->create([
                    'production_item_id' => $item->id,
                    'menu_id' => $item->menu_id,
                    'plate_color' => $item->plate_color,
                    'quantity' => 1,
                    'reason' => $reason,
                    ...$snapshot,
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