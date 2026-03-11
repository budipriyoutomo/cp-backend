<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;

class PlateColorRequest extends BaseRequest
{
    /**
     * Rules untuk CREATE
     */
    protected function rulesForCreate(): array
    {
        return [
            'platename'        => 'required|string|max:100|unique:plate_colors,platename',
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
            'platename'        => 'required|string|max:100|unique:plate_colors,platename,' . $this->route('id'),
            'price'            => 'required|numeric|min:0',
            'description'      => 'nullable|string|max:255',
            'target_foodcost'  => 'nullable|numeric|min:0|max:100',
            'is_active'        => 'nullable|boolean',
        ];
    }
}