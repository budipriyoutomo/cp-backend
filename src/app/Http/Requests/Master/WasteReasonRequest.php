<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class WasteReasonRequest extends BaseRequest
{
    /**
     * Rules untuk CREATE
     *
     * Reason baru wajib punya kategori. Reason lama boleh masih kosong — karena
     * itu di update kategori tidak wajib.
     */
    protected function rulesForCreate(): array
    {
        return [
            'reason_name'       => 'required|string|max:255|unique:waste_reasons,reason_name',
            'description'       => 'nullable|string|max:255',
            'waste_category_id' => ['required', ...$this->categoryRules()],
            'is_active'         => 'nullable|boolean',
        ];
    }

    /**
     * Rules untuk UPDATE
     */
    protected function rulesForUpdate(): array
    {
        return [
            'reason_name'       => 'required|string|max:255|unique:waste_reasons,reason_name,' . $this->route('id'),
            'description'       => 'nullable|string|max:255',
            'waste_category_id' => ['nullable', ...$this->categoryRules()],
            'is_active'         => 'nullable|boolean',
        ];
    }

    /**
     * `uuid` sebelum `exists` — lihat Support\Uuid. Hanya kategori aktif yang
     * boleh dipilih.
     */
    private function categoryRules(): array
    {
        return [
            'uuid',
            Rule::exists('waste_categories', 'id')
                ->whereNull('deleted_at')
                ->where('is_active', true),
        ];
    }
}
