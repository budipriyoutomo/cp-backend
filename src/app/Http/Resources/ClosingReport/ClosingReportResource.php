<?php

namespace App\Http\Resources\ClosingReport;

use App\Http\Resources\BaseResource;

class ClosingReportResource extends BaseResource
{
    public function toArray($request): array
    {
        $entries = $this->whenLoaded('entries');
        $entriesCollection = $this->relationLoaded('entries')
            ? $this->entries
            : collect();

        $totalProduced = (int) $entriesCollection->sum('produced');
        $totalSold = (int) $entriesCollection->sum('sold');
        $totalWaste = (int) $entriesCollection->sum('waste');
        $totalPosSold = (int) $entriesCollection->sum('pos_sold');
        $totalAdjustment = (int) $entriesCollection->sum('adjustment');
        $totalCompensation = (int) $entriesCollection->sum('compensation');
        $totalCompensationValue = (float) $entriesCollection->sum(function ($entry) {
            return (int) $entry->compensation * (float) ($entry->plateColor?->price ?? 0);
        });

        return [
            'id' => $this->id,
            'outletId' => $this->outlet_id,
            'outletName' => $this->outlet?->name,
            'date' => optional($this->date)->format('Y-m-d'),
            'status' => $this->status,
            'totalProduced' => $totalProduced,
            'totalSold' => $totalSold,
            'totalWaste' => $totalWaste,
            'totalPosSold' => $totalPosSold,
            'totalAdjustment' => $totalAdjustment,
            'totalCompensation' => $totalCompensation,
            'totalCompensationValue' => $totalCompensationValue,
            'wastePercentage' => $totalProduced > 0 ? round(($totalWaste / $totalProduced) * 100, 2) : 0,
            'kitchenLeader' => $this->kitchen_leader,
            'operationLeader' => $this->operation_leader,
            'wastePhotoUrls' => $this->waste_photo_urls ?? [],
            'notes' => $this->notes,
            'entries' => ClosingReportEntryResource::collection($entries),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'submittedAt' => $this->submitted_at?->toDateTimeString(),
            'submittedBy' => $this->submitted_by,
        ];
    }
}
