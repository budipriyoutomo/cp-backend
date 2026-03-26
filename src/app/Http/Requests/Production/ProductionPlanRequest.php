<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class ProductionPlanRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'date'      => ['required', 'date'],
            'outletId'  => ['required', 'uuid'],
            'plan'      => ['required', 'array'],

            'plan.*.timeSlot' => ['required', 'string'],

            // dynamic plate color
            'plan.*' => ['array'],
        ];
    }
}