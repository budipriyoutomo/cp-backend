<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class WasteReasonResource extends BaseResource
{
    // Sisanya otomatis lewat BaseResource.
    protected array $textFields = ['reason_name', 'description'];
}
