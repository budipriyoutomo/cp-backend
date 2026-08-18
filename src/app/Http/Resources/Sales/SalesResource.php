<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesResource extends BaseResource
{
    // `sales_headers.outlet_id` bertipe varchar, bukan uuid — id lama bisa saja
    // berupa angka. `status` selalu 'draft'/'submitted', tapi didaftarkan juga
    // supaya niatnya terbaca: kolom ini teks, bukan angka.
    protected array $textFields = ['outlet_id', 'status'];

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