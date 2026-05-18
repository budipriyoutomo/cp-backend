<?php

namespace App\Http\Resources\ClosingReport;

use App\Http\Resources\BaseResource;

class ClosingReportEntryResource extends BaseResource
{
    public function toArray($request): array
    {
        $plateColor = $this->whenLoaded('plateColor');
        $sellingPrice = $this->plateColor?->price ?? 0;

        return [
            'id' => $this->id,
            'plateColorId' => $this->plate_color_id,
            'plateColorName' => $this->plateColor?->platename,
            'plateColorCode' => $this->plateColor?->platename,
            'sellingPrice' => (float) $sellingPrice,
            'produced' => (int) $this->produced,
            'sold' => (int) $this->sold,
            'waste' => (int) $this->waste,
            'posSold' => (int) $this->pos_sold,
            'adjustment' => (int) $this->adjustment,
            'compensation' => (int) $this->compensation,
            'compensationReason' => $this->compensation_reason,
            'selisih' => (int) $this->selisih,
        ];
    }
}
