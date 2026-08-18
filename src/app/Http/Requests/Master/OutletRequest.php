<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Concerns\ScopedToBrand;

class OutletRequest extends BaseRequest
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
            'code'            => 'required|string|max:100|unique:outlets,code',
            'name'            => 'required|string|max:255',
            'brand'           => 'nullable|string|max:255',
            'brand_id'        => $this->brandIdRules(),
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
            'brand_id'        => $this->brandIdRules(),
            'address'         => 'nullable|string|max:255',
            'is_active'       => 'nullable|boolean',
        ];
    }

}