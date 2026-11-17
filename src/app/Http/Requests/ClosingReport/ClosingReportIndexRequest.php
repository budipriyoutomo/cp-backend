<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

/**
 * `GET /closing-reports`.
 *
 * `outletId` wajib, admin sekalipun — sama seperti `ClosingReportOutboxIndexRequest`.
 * Dulu `sometimes`: tanpa parameter, middleware `outlet.access` tidak punya
 * apa pun untuk diperiksa dan service mengembalikan laporan semua outlet ke
 * user `operation`/`report` mana pun. Kalau suatu saat butuh layar lintas
 * outlet, buat endpoint terpisah di belakang `role:admin`, jangan longgarkan ini.
 */
class ClosingReportIndexRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            // `uuid` sebelum `exists`: kolomnya uuid di PostgreSQL, jadi tanpa
            // penjaga bentuk validatornya sendiri yang menjawab 500.
            'outletId'  => ['required', 'uuid', 'exists:outlets,id'],
            'status'    => ['nullable', 'string', 'max:20'],
            'startDate' => ['nullable', 'date'],
            'endDate'   => ['nullable', 'date', 'after_or_equal:startDate'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
