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

    protected function buildQuery(Request $request)
    {
        $query = $this->query();

        // include
        $includes = $request->query('include');
        $relations = $this->relations;

        if ($includes) {
            $relations = array_merge($relations, explode(',', $includes));
        }

        if (!empty($relations)) {
            $query->with(array_unique($relations));
        }

        // search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                foreach ($this->searchable as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        // filter
        foreach ($request->query() as $key => $value) {
            if (in_array($key, $this->searchable)) {
                $query->where($key, $value);
            }
        }

        // sort
        if ($sort = $request->query('sort')) {
            foreach (explode(',', $sort) as $field) {
                $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
                $field = ltrim($field, '-');

                if (in_array($field, $this->sortable)) {
                    $query->orderBy($field, $direction);
                }
            }
        }

        return $query;
    }

    public function list(Request $request): LengthAwarePaginator
    { 
        $query = $this->buildQuery($request);

        if ($request->query('per_page') === 'all') {
            return $query->get();
        }

        return $query->paginate((int) $request->query('per_page', 15));
    }

    public function all(Request $request)
    {
        return $this->buildQuery($request)->get();
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