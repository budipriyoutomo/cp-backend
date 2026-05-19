<?php

namespace App\Services\Sales;

use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use App\Http\Resources\Sales\SalesClosingReportResource;
use Illuminate\Database\Eloquent\Model;

use App\Services\BaseAggregateService;
use Illuminate\Http\Request; 
use Illuminate\Pagination\LengthAwarePaginator;

class SalesService extends BaseAggregateService
{
    protected string $model = SalesHeader::class;

    protected string $itemModel = SalesItem::class;
    protected string $itemForeignKey = 'sales_id';

    // ==========================
    // OVERRIDE CREATE ITEMS
    // ==========================
    protected function createItems(Model $model, array $items): void
    {
        foreach ($items as $item) {

            $details = $item['details'] ?? [];
            unset($item['details']);

            $adjustment = $item['adjustment'] ?? 0;
            $compensation = $item['compensation'] ?? 0;

            // 🔥 HITUNG ULANG SELISIH
            $item['selisih'] = $item['pos_sold'] - (
                $item['production_sold'] + $adjustment + $compensation
            );

            $item[$this->itemForeignKey] = $model->id;

            $this->applyFingerprint($item);

            $salesItem = SalesItem::create($item);

            // 🔹 SAVE DETAILS
            $this->createDetails($salesItem, $details);
        }
    }

    // ==========================
    // OVERRIDE SYNC ITEMS
    // ==========================
    protected function syncItems(Model $model, array $items): void
    {
        $existingIds = SalesItem::where('sales_id', $model->id)
            ->pluck('id')
            ->toArray();

        $incomingIds = [];

        foreach ($items as $item) {

            $details = $item['details'] ?? [];
            unset($item['details']);

            $adjustment = $item['adjustment'] ?? 0;
            $compensation = $item['compensation'] ?? 0;

            // 🔥 HITUNG ULANG
            $item['selisih'] = $item['pos_sold'] - (
                $item['production_sold'] + $adjustment + $compensation
            );

            // UPDATE
            if (!empty($item['id'])) {

                $incomingIds[] = $item['id'];

                $record = SalesItem::find($item['id']);
                if ($record) {
                    unset($item['id']);
                    $this->applyFingerprint($item, false);

                    $record->update($item);

                    // 🔹 SYNC DETAILS
                    $this->syncDetails($record, $details);
                }
            }
            // CREATE
            else {
                $item['sales_id'] = $model->id;

                $this->applyFingerprint($item);

                $newItem = SalesItem::create($item);

                $this->createDetails($newItem, $details);
            }
        }

        // DELETE ITEMS
        $deleteIds = array_diff($existingIds, $incomingIds);

        if (!empty($deleteIds)) {
            SalesItem::whereIn('id', $deleteIds)->delete();

            // 🔥 HAPUS DETAIL JUGA
            SalesItemDetail::whereIn('sales_item_id', $deleteIds)->delete();
        }
    }

    // ==========================
    // DETAIL HANDLER
    // ==========================
    protected function createDetails(SalesItem $item, array $details): void
    {
        foreach ($details as $detail) {

            $detail['sales_item_id'] = $item->id;

            $this->applyFingerprintDetail($detail);

            SalesItemDetail::create($detail);
        }
    }

    protected function syncDetails(SalesItem $item, array $details): void
    {
        $existingIds = SalesItemDetail::where('sales_item_id', $item->id)
            ->pluck('id')
            ->toArray();

        $incomingIds = [];

        foreach ($details as $detail) {

            // UPDATE
            if (!empty($detail['id'])) {

                $incomingIds[] = $detail['id'];

                $record = SalesItemDetail::find($detail['id']);
                if ($record) {
                    unset($detail['id']);
                    $this->applyFingerprintDetail($detail, false);
                    $record->update($detail);
                }
            }
            // CREATE
            else {
                $detail['sales_item_id'] = $item->id;
                $this->applyFingerprintDetail($detail);

                SalesItemDetail::create($detail);
            }
        }

        // DELETE
        $deleteIds = array_diff($existingIds, $incomingIds);

        if (!empty($deleteIds)) {
            SalesItemDetail::whereIn('id', $deleteIds)->delete();
        }
    }

    // ==========================
    // DETAIL FINGERPRINT
    // ==========================
    protected function applyFingerprintDetail(array &$item, bool $isCreate = true): void
    {
        if ($isCreate) {
            $item['created_by'] = auth()->id();
        }

        $item['updated_by'] = auth()->id();
    }

    // ==========================
    // QUERY
    // ==========================
    public function getByDateOutlet(string $outletId, string $date)
    {
        return SalesHeader::with(['outlet', 'items.details.menu.plateColor', 'items.plateColor'])
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->first();
    }

    public function show(string $id)
    {
        return SalesHeader::with(['items.details', 'items.plateColor'])
            ->findOrFail($id);
    }

   
    public function list(Request $request): LengthAwarePaginator
    {
        $query = $this->query();

        // ==========================
        // RELATIONS (custom)
        // ==========================
        $query->with([
            'items.details',
            'items.plateColor'
        ]);

        // ==========================
        // FILTER KHUSUS
        // ==========================
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // ==========================
        // SEARCH (optional)
        // ==========================
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->orWhere('notes', 'like', "%{$search}%");
                // tambahin field lain kalau perlu
            });
        }

        // ==========================
        // SORT (default terbaru)
        // ==========================
        $query->orderBy('date', 'desc');

        // ==========================
        // PAGINATION
        // ==========================
        $perPage = min((int) $request->query('per_page', 15), 100);

        return $query->paginate($perPage);
    }

    public function getClosingReportData(string $outletId, string $date): array
    {
        $salesHeader = SalesHeader::query()
                ->with([
                    'outlet',
                    'items.platecolor',
                    'items.details.menu.category',
                    'items.details.menu.plateColor',
                ])
                ->where('outlet_id', $outletId)
                ->whereDate('date', $date) 
                ->whereNotNull('submitted_at') 
                ->where('status', 'submitted') 
                ->first();

            if (!$salesHeader) {

                return [
                    'status' => false,
                    'message' => 'No submitted sales data found',
                    'data' => null,
                ];
            }

            return [
                'status' => true,
                'message' => 'Success',
                'data' => (
                    new SalesClosingReportResource($salesHeader)
                )->resolve(),
            ];
    }
}
