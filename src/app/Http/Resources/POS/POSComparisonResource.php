<?php

namespace App\Http\Resources\POS;

use Illuminate\Http\Resources\Json\JsonResource;

class POSComparisonResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'plateColorId' => $this['plateColorId'],
            'plateColorName' => $this['plateColorName'],
            'posSold' => $this['posSold'],
            'productionSold' => $this['productionSold'],
            'selisih' => $this['selisih'],
        ];
    }
}