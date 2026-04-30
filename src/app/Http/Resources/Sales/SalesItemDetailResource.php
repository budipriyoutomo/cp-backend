<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesItemDetailResource extends BaseResource
{
    public function toArray($request): array
    {
        return array_merge(
            $this->autoDetect(),
            $this->systemFields()
        );
    }
}