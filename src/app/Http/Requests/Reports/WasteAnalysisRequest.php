<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\BaseRequest;

class WasteAnalysisRequest extends BaseRequest
{
    protected function commonRules(): array
    {
        return array_merge(parent::commonRules(), [
            'outletId'  => ['required', 'uuid'],
            'startDate' => ['required', 'date'],
            'endDate'   => ['required', 'date', 'after_or_equal:startDate'],
        ]);
    }

    public function messages(): array
    {
        return [
            'outletId.required'  => 'Outlet is required',
            'outletId.uuid'      => 'Outlet ID is invalid',
            'startDate.required' => 'Start date is required',
            'endDate.required'   => 'End date is required',
            'endDate.after_or_equal' => 'End date must be on or after the start date',
        ];
    }
}
