<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class ProductionRemoveExpiredRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'itemIds'   => ['required', 'array'],
            'itemIds.*' => ['uuid', 'exists:production_items,id'],
        ];
    }
}