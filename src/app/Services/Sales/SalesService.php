<?php

namespace App\Services\Sales;

use App\Models\Sales;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use App\Services\BaseAggregateService;

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
        return SalesHeader::with(['items.details', 'items.plateColor'])
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->first();
    }

    public function show(string $id)
    {
        return SalesHeader::with(['items.details', 'items.plateColor'])
            ->findOrFail($id);
    }
}