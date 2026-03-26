<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class WasteIndexRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId'  => ['required', 'uuid'],
            'startDate' => ['required', 'date'],
            'endDate'   => ['required', 'date'],
        ];
    }
}