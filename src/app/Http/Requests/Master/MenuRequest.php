<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Concerns\ScopedToBrand;

class MenuRequest extends BaseRequest
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
            'code'            => ['required', 'string', 'max:50', $this->uniqueWithinBrand('menus', 'code')],
            'menuname'        => ['required', 'string', 'max:150', $this->uniqueWithinBrand('menus', 'menuname')],
            'description'     => 'nullable|string|max:255',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp',

            'price'           => 'required|numeric|min:0',
            'shelf_life'      => 'nullable|integer|min:0',

            'plate_color_id'  => 'required|uuid|exists:plate_colors,id',
            'brand_id'        => $this->brandIdRules(),

            'is_active'       => 'nullable|boolean',
        ];
    }

    /**
     * Rules untuk UPDATE
     */
    protected function rulesForUpdate(): array
    {
        return [
            'code'            => ['required', 'string', 'max:50', $this->uniqueWithinBrand('menus', 'code', $this->route('id'))],
            'menuname'        => ['required', 'string', 'max:150', $this->uniqueWithinBrand('menus', 'menuname', $this->route('id'))],
            'description'     => 'nullable|string|max:255',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp',

            'price'           => 'required|numeric|min:0',
            'shelf_life'      => 'nullable|integer|min:0',

            'plate_color_id'  => 'required|uuid|exists:plate_colors,id',
            'brand_id'        => $this->brandIdRules(),

            'is_active'       => 'nullable|boolean',
        ];
    }
}
