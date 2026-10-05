<?php

namespace App\Http\Resources\ClosingReport;

use App\Models\ClosingReportOutbox;
use App\Http\Resources\BaseResource;

/**
 * Satu baris di layar admin "Kirim Ulang BI".
 *
 * @mixin ClosingReportOutbox
 */
class ClosingReportOutboxResource extends BaseResource
{
    public function toArray($request): array
    {
        $maxAttempts = (int) config('rabbitmq.closing_report.max_attempts');
        $report = $this->closingReport;

        return [
            'id'              => $this->id,
            'closingReportId' => $this->closing_report_id,
            'date'            => $report?->date?->format('Y-m-d'),
            'outletId'        => $report?->outlet_id,
            'outletCode'      => $report?->outlet?->code,
            'outletName'      => $report?->outlet?->name,
            'status'          => $this->status,
            'attempts'        => $this->attempts,
            'maxAttempts'     => $maxAttempts,
            // Berhenti dicoba otomatis — hanya kirim ulang manual yang tersisa.
            'exhausted'       => $this->status !== ClosingReportOutbox::STATUS_PUBLISHED
                && $this->attempts >= $maxAttempts,
            'lastError'       => $this->last_error,
            'nextAttemptAt'   => $this->next_attempt_at?->toIso8601String(),
            'publishedAt'     => $this->published_at?->toIso8601String(),
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}
