<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Concerns\ScopedToBrand;

class PlateColorRequest extends BaseRequest
{
    use ScopedToBrand;

    protected function prepareForValidation()
    {
        parent::prepareForValidation();

        $this->fillSoleBrand();
    }

    /**
     * Rules untuk CREATE
     */
    protected function rulesForCreate(): array
    {
        return [
            'platename'        => ['required', 'string', 'max:100', $this->uniqueWithinBrand('plate_colors', 'platename')],
            // Warna tampil badge. Nullable: baris lama boleh belum punya, dan
            // badge jatuh ke warna cadangan. Bentuk pendek '#RGB' ditolak
            // supaya penyimpanan seragam.
            'color_hex'        => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_id'         => $this->brandIdRules(),
            'price'            => 'required|numeric|min:0',
            'description'      => 'nullable|string|max:255',
            'target_foodcost'  => 'nullable|numeric|min:0|max:100',
            'is_active'        => 'nullable|boolean',
        ];
    }

    /**
     * Rules untuk UPDATE
     */
    protected function rulesForUpdate(): array
    {
        return [
            'platename'        => ['required', 'string', 'max:100', $this->uniqueWithinBrand('plate_colors', 'platename', $this->route('id'))],
            'color_hex'        => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_id'         => $this->brandIdRules(),
            'price'            => 'required|numeric|min:0',
            'description'      => 'nullable|string|max:255',
            'target_foodcost'  => 'nullable|numeric|min:0|max:100',
            'is_active'        => 'nullable|boolean',
        ];
    }
}
