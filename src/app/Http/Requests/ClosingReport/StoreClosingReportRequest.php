<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

class StoreClosingReportRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId' => ['required', 'uuid', 'exists:outlets,id'],
            'date' => ['required', 'date'],
            'kitchenLeader' => ['nullable', 'string', 'max:255'],
            'operationLeader' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            'entries' => ['required', 'array', 'min:1'],
            'entries.*.plateColorId' => ['required', 'uuid', 'exists:plate_colors,id'],
            'entries.*.posSold' => ['nullable', 'integer', 'min:0'],
            'entries.*.adjustment' => ['nullable', 'integer'],
            'entries.*.compensation' => ['nullable', 'integer', 'min:0'],
            'entries.*.compensationReason' => ['nullable', 'string'],
        ];
    }

    protected function rulesForUpdate(): array
    {
        return [
            'outletId' => ['sometimes', 'uuid', 'exists:outlets,id'],
            'date' => ['sometimes', 'date'],
            'kitchenLeader' => ['nullable', 'string', 'max:255'],
            'operationLeader' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            'entries' => ['sometimes', 'array', 'min:1'],
            'entries.*.plateColorId' => ['required_with:entries', 'uuid', 'exists:plate_colors,id'],
            'entries.*.posSold' => ['nullable', 'integer', 'min:0'],
            'entries.*.adjustment' => ['nullable', 'integer'],
            'entries.*.compensation' => ['nullable', 'integer', 'min:0'],
            'entries.*.compensationReason' => ['nullable', 'string'],
        ];
    }
}
