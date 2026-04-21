<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;

class ProductionMenuResource extends BaseResource
{
    public function toArray($request) : array
    {
        return [
            'menuId' => $this->menu_id,
            'menuName' => $this->menu?->menuname ?? 'Unknown',

            'totalProduced' => (int) $this->total_produced,
            'totalSold' => (int) $this->total_sold,
            'totalWasted' => (int) $this->total_wasted,
        ];
    }
}