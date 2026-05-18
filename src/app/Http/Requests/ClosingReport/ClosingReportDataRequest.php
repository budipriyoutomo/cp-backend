<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

class ClosingReportDataRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId' => ['required', 'exists:outlets,id'],
            'date' => ['required', 'date'],
        ];
    }
}
