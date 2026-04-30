<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesResource extends BaseResource
{
    public function toArray($request): array
    {
        return array_merge(
            $this->autoDetect(),

            [
                'items' => SalesItemResource::collection(
                    $this->whenLoaded('items')
                ),
            ],

            $this->systemFields()
        );
    }
}