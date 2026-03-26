<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Exception;

class BaseService
{
    protected string $model;

    protected array $relations = [];
    protected array $searchable = [];
    protected array $sortable = [];


    protected function query(): Builder
    {
        return app($this->model)->newQuery();
    }

    public function list(Request $request): LengthAwarePaginator
    {
        $query = $this->query();

        // 🔥 INCLUDE RELATION (?include=plateColor)
        $includes = $request->query('include');
        $relations = $this->relations;

        if ($includes) {
            $requested = explode(',', $includes);
            $relations = array_merge($relations, $requested);
        }

        if (!empty($relations)) {
            $query->with(array_unique($relations));
        }

        // 🔥 SEARCH (?search=salmon)
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                foreach ($this->searchable as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        // 🔥 FILTER (?plate_color_id=xxx)
        foreach ($request->query() as $key => $value) {
            if (in_array($key, $this->searchable)) {
                $query->where($key, $value);
            }
        }

        // 🔥 SORT (?sort=price,-created_at)
        if ($sort = $request->query('sort')) {
            $fields = explode(',', $sort);

            foreach ($fields as $field) {
                $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
                $field = ltrim($field, '-');

                if (in_array($field, $this->sortable)) {
                    $query->orderBy($field, $direction);
                }
            }
        }

        // 🔥 PAGINATION
        $perPage = (int) $request->query('per_page', 15);

        return $query->paginate($perPage);
    }

    public function find($id, array $with = []): ?Model
    {
        $query = $this->query();

        $relations = array_merge($this->relations, $with);

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    public function create(array $data): Model
    {
        return $this->query()->create($data);
    }

    public function update($id, array $data): ?Model
    {
        $model = $this->find($id);

        if (!$model) {
            throw new Exception("Data not found");
        }

        $model->update($data);

        return $model;
    }

    public function delete($id): ?Model
    {
        $model = $this->find($id);

        if (!$model) {
            throw new Exception("Data not found");
        }

        if (Schema::hasColumn($model->getTable(), 'is_active')) {
            $model->is_active = 0;
            $model->save();
        }

        $model->delete();

        return $model;
    }
}