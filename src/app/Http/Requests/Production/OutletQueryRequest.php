<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * GET produksi yang hanya butuh outlet: `stats`, `conveyor-grouped`,
 * `expired-grouped`.
 *
 * Service-nya ber-type-hint `string $outletId`. Tanpa validasi, outlet yang
 * hilang menjadi TypeError (500), dan bentuk yang salah menjadi 22P02 (500,
 * SQL bocor) di PostgreSQL. FormRequest, bukan `$request->validate()` di
 * controller, supaya kegagalannya memakai envelope `{ status, message, errors }`
 * dari `BaseRequest::failedValidation()`.
 */
class OutletQueryRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId' => ['required', 'uuid'],
        ];
    }
}
