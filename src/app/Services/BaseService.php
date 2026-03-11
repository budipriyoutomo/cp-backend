<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

class BaseService
{
    protected string $model;

    /**
     * Return model instance
     */
    protected function query(): Builder
    {
        return app($this->model)->newQuery();
    }

    /**
     * Get all data
     */
    public function all($withTrashed = false)
    {
        $query = $this->query();

        if ($withTrashed && method_exists($query->getModel(), 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        return $query->get();
    }

    /**
     * Paginate data
     */
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }

    /**
     * Find specific record
     */
    public function find($id, $withTrashed = false): ?Model
    {
        $query = $this->query();

        if ($withTrashed && method_exists($query->getModel(), 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    /**
     * Create record
     */
    public function create(array $data): Model
    {
        return $this->query()->create($data);
    }

    /**
     * Update record
     */
    public function update($id, array $data): ?Model
    {
        $model = $this->find($id);

        if (!$model) {
            throw new Exception("Data not found");
        }

        $model->update($data);

        return $model;
    }

    /**
     * Delete (soft delete)
     */
    public function delete($id): ?Model
    {
        $model = $this->find($id);

        if (!$model) {
            throw new Exception("Data not found");
        }
            
        // Update is_active jika kolomnya ada
        if (Schema::hasColumn($model->getTable(), 'is_active')) {
            $model->is_active = 0;
            $model->save(); // update sebelum soft delete
        }

        $model->delete();

        return $model;
    }

    /**
     * Restore (untuk soft delete)
     */
    public function restore($id): ?Model
    {
        $model = $this->find($id, true); // withTrashed

        if (!$model) {
            throw new Exception("Data not found");
        }

        if (method_exists($model, 'restore')) {
            $model->restore();
        }

        return $model;
    }

    /**
     * Permanent delete
     */
    public function forceDelete($id): bool
    {
        $model = $this->find($id, true);

        if (!$model) {
            throw new Exception("Data not found");
        }

        if (method_exists($model, 'forceDelete')) {
            return $model->forceDelete();
        }

        throw new Exception("forceDelete not supported on this model");
    }
}
