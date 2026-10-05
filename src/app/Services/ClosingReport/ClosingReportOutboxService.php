<?php

namespace App\Services\ClosingReport;

use App\Exceptions\BusinessRuleException;
use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Services\Messaging\MessagePublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mengantre dan mengirim pesan `closingreport.submitted` ke BI.
 *
 * enqueue() dipanggil DI DALAM transaksi submit, publish() SETELAH commit.
 * Urutan itu yang menjamin dua hal: submit yang di-rollback tidak pernah
 * mengirim pesan, dan submit yang sukses tidak pernah kehilangan pesannya
 * meski broker sedang mati.
 */
class ClosingReportOutboxService
{
    private const MAX_RETRY_DELAY_MINUTES = 60;

    /**
     * Masa tenggang sebelum scheduler boleh menyentuh baris baru. Tanpa ini
     * scheduler bisa mengambil baris di antara commit submit dan publish
     * langsung, lalu pesan yang sama terkirim dua kali.
     */
    private const IMMEDIATE_PUBLISH_GRACE_MINUTES = 2;

    public function __construct(
        private readonly ClosingReportPayloadBuilder $payloads,
        private readonly MessagePublisher $publisher,
    ) {
    }

    public function enqueue(ClosingReport $report): ClosingReportOutbox
    {
        return ClosingReportOutbox::firstOrCreate(
            ['closing_report_id' => $report->id],
            [
                'payload'         => $this->payloads->build($report),
                'next_attempt_at' => now()->addMinutes(self::IMMEDIATE_PUBLISH_GRACE_MINUTES),
            ],
        );
    }

    /**
     * Coba kirim satu baris. Tidak pernah melempar: kegagalan dicatat di baris
     * itu dan dijadwalkan ulang, supaya pemanggil (submit, scheduler) tidak
     * ikut gagal karena broker.
     */
    public function publish(ClosingReportOutbox $outbox): bool
    {
        if ($outbox->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            return true;
        }

        try {
            $this->publisher->publish(
                config('rabbitmq.closing_report.exchange'),
                config('rabbitmq.closing_report.routing_key'),
                $outbox->payload + ['sentAt' => now()->toIso8601String()],
                $outbox->closing_report_id,
            );
        } catch (Throwable $e) {
            $attempt = $outbox->attempts + 1;
            $outbox->markFailed($e->getMessage(), now()->addMinutes($this->retryDelayMinutes($attempt)));

            Log::warning('Publish closing report gagal, akan dicoba ulang', [
                'closing_report_id' => $outbox->closing_report_id,
                'attempt'           => $attempt,
                'error'             => $e->getMessage(),
            ]);

            return false;
        }

        $outbox->markPublished();

        return true;
    }

    /**
     * Kirim ulang manual (layar admin, `publish-pending --id`): mengabaikan
     * jadwal dan batas percobaan. Baris yang sudah terkirim ditolak — mengirim
     * ulang data yang sudah sampai adalah permintaan BI, bukan tombol.
     *
     * @return ClosingReportOutbox baris setelah percobaan; cek `status`-nya
     *
     * @throws BusinessRuleException 409 kalau sudah terkirim
     */
    public function resend(ClosingReportOutbox $outbox): ClosingReportOutbox
    {
        if ($outbox->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            throw new BusinessRuleException('Laporan ini sudah terkirim ke BI.', 409);
        }

        $this->publish($outbox);

        return $outbox->fresh(['closingReport.outlet']);
    }

    /**
     * Daftar untuk layar admin, selalu dalam satu outlet. Terbaru dulu.
     *
     * @param  array{outletId: string, status?: ?string, startDate?: ?string, endDate?: ?string, per_page?: ?int}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $status = $filters['status'] ?? 'unpublished';

        $query = ClosingReportOutbox::query()
            ->select('closing_report_outbox.*')
            ->join('closing_reports', 'closing_reports.id', '=', 'closing_report_outbox.closing_report_id')
            ->where('closing_reports.outlet_id', $filters['outletId'])
            ->whereNull('closing_reports.deleted_at')
            ->with('closingReport.outlet');

        match ($status) {
            'published' => $query->where('closing_report_outbox.status', ClosingReportOutbox::STATUS_PUBLISHED),
            'all'       => null,
            default     => $query->where('closing_report_outbox.status', '!=', ClosingReportOutbox::STATUS_PUBLISHED),
        };

        if (! empty($filters['startDate'])) {
            $query->whereDate('closing_reports.date', '>=', $filters['startDate']);
        }
        if (! empty($filters['endDate'])) {
            $query->whereDate('closing_reports.date', '<=', $filters['endDate']);
        }

        return $query
            ->orderByDesc('closing_reports.date')
            ->orderByDesc('closing_report_outbox.created_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /** Jeda sebelum percobaan berikutnya: 1, 2, 4, … menit, maksimal 60. */
    public function retryDelayMinutes(int $attempt): int
    {
        return min(2 ** max($attempt - 1, 0), self::MAX_RETRY_DELAY_MINUTES);
    }
}
