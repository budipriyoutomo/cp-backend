<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;

class ProductionItemResource extends BaseResource
{
    public function toArray($request): array
    {
        return array_merge([
            'id'          => $this->id,
            'menuId'      => $this->menu_id,
            'menuName'    => $this->menu?->menuname,
            'plateColor'  => $this->plate_color,
            'plateColorName' => $this->menu?->plateColor?->platename,
            'quantity'    => $this->quantity,
            'producedAt'  => $this->produced_at,
            'expiresAt'   => $this->expires_at,
            'beltStatus'      => $this->belt_status,
            'finalStatus'     => $this->final_status,
            'soldAt'         => $this->sold_at,
            'wastedAt'       => $this->wasted_at,
            'outletId'    => $this->outlet_id,
            'timeOnBelt'  => $this->time_on_belt,
        ], $this->systemFields());
    }
}