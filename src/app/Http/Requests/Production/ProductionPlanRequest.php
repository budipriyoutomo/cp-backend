<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class ProductionPlanRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'plan' => ['required', 'array'],
            'plan.*.timeSlot' => ['required', 'string'],
            'plan.*.items' => ['required', 'array'],

            'plan.*.items.*.plateColorId' => ['required', 'uuid'],
            'plan.*.items.*.qty' => ['required', 'integer', 'min:0'],
        ];
    }
}