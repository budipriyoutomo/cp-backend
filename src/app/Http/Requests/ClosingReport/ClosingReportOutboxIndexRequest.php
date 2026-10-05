<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

class ClosingReportOutboxIndexRequest extends BaseRequest
{
    public const STATUS_UNPUBLISHED = 'unpublished';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ALL = 'all';

    protected function rulesForCreate(): array
    {
        return [
            // Wajib: tidak ada endpoint yang mengembalikan data lintas outlet
            // tanpa filter eksplisit, admin sekalipun.
            'outletId'  => ['required', 'uuid', 'exists:outlets,id'],
            'status'    => ['nullable', 'in:' . implode(',', [self::STATUS_UNPUBLISHED, self::STATUS_PUBLISHED, self::STATUS_ALL])],
            'startDate' => ['nullable', 'date'],
            'endDate'   => ['nullable', 'date', 'after_or_equal:startDate'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
