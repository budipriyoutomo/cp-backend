<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

abstract class BaseAggregateService extends BaseService
{
    protected string $itemModel;
    protected string $itemForeignKey;

    // ==========================
    // OPTIONAL LIFECYCLE HOOKS
    // ==========================
    protected function afterCreate(Model $model): void {}
    protected function beforeSync(Model $model): void {}
    protected function afterUpdate(Model $model): void {}

    // ==========================
    // CREATE (HEADER + ITEMS)
    // ==========================
    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data) {

            $items = $data['items'] ?? [];
            unset($data['items']);

            $model = parent::create($data);

            $this->createItems($model, $items);
            
            $this->afterCreate($model);

            return $model;
        });
    }

    // ==========================
    // UPDATE + SYNC ITEMS
    // ==========================
    public function update($id, array $data): Model
    {
        return DB::transaction(function () use ($id, $data) {

            $items = $data['items'] ?? [];
            unset($data['items']);

            $model = parent::update($id, $data);

            $this->beforeSync($model);
             
            $this->syncItems($model, $items);
            
            $this->afterUpdate($model);

            return $model;
        });
    }

    // ==========================
    // CREATE ITEMS
    // ==========================
    protected function createItems(Model $model, array $items): void
    {
        foreach ($items as $item) {
            $item[$this->itemForeignKey] = $model->id;

            $this->applyFingerprint($item);

            $this->itemModel::create($item);
        }
    }

    // ==========================
    // SYNC ITEMS (CORE LOGIC)
    // ==========================
    protected function syncItems(Model $model, array $items): void
    {
        $itemModel = $this->itemModel;
        $fk = $this->itemForeignKey;

        // ambil existing item IDs
        $existingIds = $itemModel::where($fk, $model->id)
            ->pluck('id')
            ->toArray();

        $incomingIds = [];

        foreach ($items as $item) {

            // UPDATE
            if (!empty($item['id'])) {
                $incomingIds[] = $item['id'];

                $record = $itemModel::find($item['id']);
                if ($record) {
                    unset($item['id']);
                    $this->applyFingerprint($item, false);
                    $record->update($item);
                }
            }
            // CREATE
            else {
                $item[$fk] = $model->id;
                $this->applyFingerprint($item);
                $itemModel::create($item);
            }
        }

        // DELETE items yang tidak ada di payload
        $deleteIds = array_diff($existingIds, $incomingIds);
        if (!empty($deleteIds)) {
            $itemModel::whereIn('id', $deleteIds)->delete();
        }
    }

    // ==========================
    // FINGERPRINT HANDLER
    // ==========================
    protected function applyFingerprint(array &$item, bool $isCreate = true): void
    {
        $table = (new $this->itemModel)->getTable();

        if ($isCreate && Schema::hasColumn($table, 'created_by')) {
            $item['created_by'] = auth()->id();
        }

        if (Schema::hasColumn($table, 'updated_by')) {
            $item['updated_by'] = auth()->id();
        }
    }
}
