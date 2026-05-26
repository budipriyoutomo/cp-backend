<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\BaseRequest;

class DailySummaryRequest extends BaseRequest
{
    /**
     * Common rules
     */
    protected function commonRules(): array
    {
        return array_merge(parent::commonRules(), [
            'outletId' => [
                'required',
                'uuid',
            ],

            'date' => [
                'required',
                'date',
            ],
        ]);
    }
 
    /**
     * Custom messages
     */
    public function messages(): array
    {
        return [
            'outletId.required' => 'Outlet is required',
            'outletId.uuid' => 'Outlet ID is invalid',

            'date.required' => 'Date is required',
            'date.date' => 'Date format is invalid',
        ];
    }
}