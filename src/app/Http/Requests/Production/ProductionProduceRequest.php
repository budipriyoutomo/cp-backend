<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class ProductionProduceRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'menuId'   => ['required', 'uuid', 'exists:menus,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'outletId' => ['required', 'uuid'],
        ];
    }
}