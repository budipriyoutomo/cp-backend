<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;

class OutletRequest extends BaseRequest
{
    /**
     * Rules untuk CREATE
     */
    protected function rulesForCreate(): array
    {
        return [
            'code'            => 'required|string|max:100|unique:outlets,code',
            'name'            => 'required|string|max:255',
            'brand'           => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
            'is_active'       => 'nullable|boolean',
        ];
    }
 

    /**
     * Rules untuk UPDATE
     */
    protected function rulesForUpdate(): array
    {
        return [
            'code'            => 'required|string|max:100|unique:outlets,code,' . $this->route('id'),
            'name'            => 'required|string|max:255',
            'brand'           => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
            'is_active'       => 'nullable|boolean',
        ];
    }

}