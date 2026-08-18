<?php

namespace App\Services\Master;

use App\Models\Brand;
use App\Services\BaseService;

class BrandService extends BaseService
{
    protected string $model = Brand::class;
    protected array $searchable = ['code', 'name', 'is_active'];
    protected array $sortable = ['name', 'code', 'created_at'];
}
