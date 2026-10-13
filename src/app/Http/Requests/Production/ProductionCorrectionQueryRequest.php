<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * Query string untuk ringkasan dan riwayat Koreksi Status Produksi.
 * `date` hanya wajib untuk ringkasan — controller memilih aturannya.
 */
class ProductionCorrectionQueryRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        $rules = [
            'outletId' => ['required', 'uuid', 'exists:outlets,id'],
        ];

        if ($this->routeIs('production.corrections.summary')) {
            return $rules + [
                'date' => ['required', 'date_format:Y-m-d'],
            ];
        }

        return $rules + [
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'endDate'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
