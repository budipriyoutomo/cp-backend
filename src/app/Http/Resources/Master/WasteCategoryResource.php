<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class WasteCategoryResource extends BaseResource
{
    // Sisanya otomatis lewat BaseResource. `reasons_count` ikut kalau
    // di-loadCount (WasteCategoryService selalu melakukannya).
    protected array $textFields = ['code', 'category_name', 'description'];
}
