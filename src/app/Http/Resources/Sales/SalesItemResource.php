<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesItemResource extends BaseResource
{
    public function toArray($request): array
    {
        return array_merge(
            $this->autoDetect(),

            [
                'plate_color' => $this->whenLoaded('plateColor'),

                'details' => SalesItemDetailResource::collection(
                    $this->whenLoaded('details')
                ),
            ],

            $this->systemFields()
        );
    }
}