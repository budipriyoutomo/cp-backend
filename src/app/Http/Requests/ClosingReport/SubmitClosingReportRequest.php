<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

class SubmitClosingReportRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId' => ['required', 'exists:outlets,id'],
            'date' => ['required', 'date'],
            'kitchenLeader' => ['required', 'string', 'max:255'],
            'operationLeader' => ['required', 'string', 'max:255'],
            'wastePhotoUrls' => ['nullable', 'array'],
            'wastePhotoUrls.*' => ['required_with:wastePhotoUrls', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
