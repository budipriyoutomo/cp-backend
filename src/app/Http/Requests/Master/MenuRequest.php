<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;

class MenuRequest extends BaseRequest
{
    /**
     * Rules untuk CREATE
     */
    protected function rulesForCreate(): array
    {
        return [
            'menuname'        => 'required|string|max:150|unique:menus,menuname',
            'description'     => 'nullable|string|max:255',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp',

            'price'           => 'required|numeric|min:0',
            'shelf_life'      => 'nullable|integer|min:0',

            'plate_color_id'  => 'required|uuid|exists:plate_colors,id',

            'is_active'       => 'nullable|boolean',
        ];
    }

    /**
     * Rules untuk UPDATE
     */
    protected function rulesForUpdate(): array
    {
        return [
            'menuname'        => 'required|string|max:150|unique:menus,menuname,' . $this->route('id'),
            'description'     => 'nullable|string|max:255',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp',

            'price'           => 'required|numeric|min:0',
            'shelf_life'      => 'nullable|integer|min:0',

            'plate_color_id'  => 'required|uuid|exists:plate_colors,id',

            'is_active'       => 'nullable|boolean',
        ];
    }
}