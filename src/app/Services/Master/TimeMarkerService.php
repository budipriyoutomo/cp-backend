<?php

namespace App\Services\Master;

use App\Models\TimeMarker;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesOutletBrand;
use Illuminate\Http\Request;

class TimeMarkerService extends BaseService
{
    use ResolvesOutletBrand;
    use ScopesToOutletBrandStrictly;

    protected string $model = TimeMarker::class;
    protected array $relations = ['brand'];
    protected array $searchable = ['label', 'brand_id', 'is_active'];
    protected array $sortable = ['sort_order', 'label', 'created_at'];

    protected function buildQuery(Request $request)
    {
        return $this->scopeToOutletBrand(parent::buildQuery($request), $request)
            ->orderBy('sort_order');
    }
}
