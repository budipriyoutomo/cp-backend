<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class WasteReasonResource extends BaseResource
{
    // Sisanya otomatis lewat BaseResource.
    protected array $textFields = ['reason_name', 'description'];

    /**
     * Nama dan kode kategori diratakan ke atas supaya pemakai tidak perlu
     * menggali relasi `category` (yang tetap ikut lewat autoDetect).
     */
    public function toArray($request): array
    {
        $category = $this->relationLoaded('category') ? $this->category : null;

        return array_merge(parent::toArray($request), [
            'waste_category_code' => $category?->code,
            'waste_category_name' => $category?->category_name,
        ]);
    }
}
