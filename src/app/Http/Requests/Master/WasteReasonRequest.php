<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;

class WasteReasonRequest extends BaseRequest
{
    /**
     * Rules untuk CREATE
     */
    protected function rulesForCreate(): array
    {
        return [
            'reason_name'      => 'required|string|max:255|unique:waste_reasons,reason_name',
            'description'      => 'nullable|string|max:255',
            'is_active'        => 'nullable|boolean',
        ];
    }

    /**
     * Rules untuk UPDATE
     */
    protected function rulesForUpdate(): array  
    {
        return [
            'reason_name'      => 'required|string|max:255|unique:waste_reasons,reason_name,' . $this->route('id'),
            'description'      => 'nullable|string|max:255',
            'is_active'        => 'nullable|boolean',
        ];
    }
 
}
