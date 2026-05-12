<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;

class WasteResource extends BaseResource
{
    public function toArray($request): array
    {
        return array_merge([
            'id' => $this->id, 
            'outletId' => $this->outlet_id, 
            'outletName' => $this->whenLoaded(
                'outlet',
                fn () => $this->outlet?->name
            ), 
            'time' => optional($this->recorded_at)
                ->format('H:i:s'),
            'recordedAt' => $this->recorded_at,
            'menuId' => $this->menu_id,
            'menuName' => $this->whenLoaded(
                'menu',
                fn () => $this->menu?->menuname
            ),
            'plateColorId' => $this->plate_color,
            'plateColorName' => $this->whenLoaded(
                'plateColor',
                fn () => $this->plateColor?->platename
            ),
            'plateColor' => $this->plate_color,
            'quantity' => (int) $this->quantity,
            'reason' => $this->reason,
        ], $this->systemFields());
    }
}