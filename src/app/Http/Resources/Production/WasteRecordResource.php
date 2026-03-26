<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;

class WasteRecordResource extends BaseResource
{
    public function toArray($request): array
    {
        return array_merge([
            'id'         => $this->id,
            'menuId'     => $this->menu_id, 
            'plateColor' => $this->plate_color,
            'quantity'   => $this->quantity,
            'reason'     => $this->reason,
            'recordedAt' => $this->recorded_at,
            'outletId'   => $this->outlet_id,
        ], $this->systemFields());
    }
}