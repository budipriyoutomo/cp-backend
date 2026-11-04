<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;
use App\Models\CloseDayLog;
use Illuminate\Validation\Rule;

/**
 * Query string laporan audit Tutup Hari & auto-waste. Rentang tanggal memfilter
 * hari produksi yang ditutup (`business_date`), bukan waktu eksekusinya.
 */
class CloseDayLogQueryRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId'  => ['required', 'uuid', 'exists:outlets,id'],
            'action'    => ['nullable', Rule::in(CloseDayLog::ACTIONS)],
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'endDate'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }
}
