<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;
use App\Models\ProductionStatusCorrection;

/**
 * Satu Koreksi Status Produksi.
 *
 * `lines` merangkum piring yang diubah per (menu, arah) — itulah yang diminta
 * admin. Rincian per piring (`items`) hanya dikirim di endpoint detail.
 *
 * @mixin ProductionStatusCorrection
 */
class ProductionCorrectionResource extends BaseResource
{
    private bool $withItems = false;

    public function withItems(): static
    {
        $this->withItems = true;

        return $this;
    }

    public function toArray($request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();

        $lines = $items
            ->groupBy(fn ($i) => $i->menu_id . '|' . $i->from_status . '|' . $i->to_status)
            ->map(fn ($group) => [
                'menuId'   => $group->first()->menu_id,
                'menuCode' => $group->first()->menu?->code,
                'menuName' => $group->first()->menu?->menuname,
                'from'     => $group->first()->from_status,
                'to'       => $group->first()->to_status,
                'quantity' => $group->count(),
            ])
            ->sortBy('menuName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $data = [
            'id'                  => $this->id,
            'outletId'            => $this->outlet_id,
            'outletCode'          => $this->outlet?->code,
            'outletName'          => $this->outlet?->name,
            'productionDate'      => $this->production_date?->format('Y-m-d'),
            'reason'              => $this->reason,
            'wasteReasonId'       => $this->waste_reason_id,
            'wasteReasonName'     => $this->wasteReason?->reason_name,
            'totalToWaste'        => (int) $this->total_to_waste,
            'totalToSold'         => (int) $this->total_to_sold,
            'lines'               => $lines,
            'createdBy'           => $this->created_by !== null ? trim((string) $this->created_by) : null,
            'createdByName'       => $this->creator_name,
            'createdAt'           => $this->created_at?->toIso8601String(),
        ];

        if ($this->withItems) {
            $data['items'] = $items
                ->map(fn ($i) => [
                    'productionItemId' => $i->production_item_id,
                    'menuId'           => $i->menu_id,
                    'menuName'         => $i->menu?->menuname,
                    'plateColorId'     => $i->plate_color,
                    'from'             => $i->from_status,
                    'to'               => $i->to_status,
                    'oldSoldAt'        => $i->old_sold_at?->toIso8601String(),
                    'oldWastedAt'      => $i->old_wasted_at?->toIso8601String(),
                    'wasteRecordId'    => $i->waste_record_id,
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
