<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesDraftResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'date' => optional($this->date)->format('Y-m-d'),

            'outlet_name' => optional($this->outlet)->name,

            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {

                    return [
                        // `platecolor` adalah NAMA warna, untuk ditampilkan.
                        // Id-nya dikirim terpisah karena layar Sales Input
                        // mengirim balik item draft ke `POST /sales`, dan
                        // `plate_color_id` di sana wajib uuid — dulu layar itu
                        // memakai nama sebagai id, dan submit dari draft yang
                        // dibuka ulang ditolak ("Blue" bukan uuid).
                        'plate_color_id' => $item->plate_color_id,
                        'platecolor' => optional($item->platecolor)->platename,

                        'price' => (float) optional($item->platecolor)->price,

                        'pos' => (int) ($item->pos_sold ?? 0),
                        'sold' => (int) ($item->production_sold ?? 0),
                        'production' => (int) ($item->production_sold ?? 0),
                        'waste' => (int) ($item->production_waste ?? 0),
                        'adjustment' => (int) ($item->adjustment ?? 0),
                        'compensation' => (int) ($item->compensation ?? 0),
                        'selisih' => (int) ($item->selisih ?? 0),
                    ];
                });
            }),

            ...$this->systemFields(),
        ];
    }
}