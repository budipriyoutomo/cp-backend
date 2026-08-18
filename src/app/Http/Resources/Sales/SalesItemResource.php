<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesItemResource extends BaseResource
{
    // `sales_items.plate_color_id` bertipe varchar, bukan uuid.
    protected array $textFields = ['plate_color_id'];

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