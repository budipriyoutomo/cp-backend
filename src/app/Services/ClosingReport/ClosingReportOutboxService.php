<?php

namespace App\Services\ClosingReport;

use App\Exceptions\BusinessRuleException;
use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Models\User;
use App\Support\OutletGate;
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
        private readonly OutletGate $outletGate,
    ) {
    }

    /**
     * @return ClosingReportOutbox|null null kalau laporan tidak punya satu menu
     *         pun — outlet yang tidak berproduksi tidak mengirim apa pun ke BI
     *         (keputusan bisnis; `items: []` ditolak penerima).
     */
    public function enqueue(ClosingReport $report): ?ClosingReportOutbox
    {
        $payload = $this->payloads->build($report);

        if ($payload['data']['items'] === []) {
            Log::info('Closing report tanpa menu, tidak dikirim ke BI', [
                'closing_report_id' => $report->id,
            ]);

            return null;
        }

        return ClosingReportOutbox::firstOrCreate(
            ['message_id' => (string) $report->id],
            [
                'closing_report_id' => $report->id,
                'payload'         => $payload,
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
                $outbox->messageId(),
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
    /**
     * `POST /closing-reports/outbox/{id}/resend`. Route-nya `role:admin`, jadi
     * hari ini selalu lolos; diperiksa supaya aturannya tetap satu dengan
     * endpoint lain. Outbox tidak punya `outlet_id` — outletnya milik laporan.
     * CLI (`publish-pending --id`) tidak punya user dan tetap memakai resend().
     */
    public function resendFor(User $actor, string $id): ClosingReportOutbox
    {
        $outbox = ClosingReportOutbox::with('closingReport')->findOrFail($id);

        $this->outletGate->authorize($actor, (string) $outbox->closingReport?->outlet_id);

        return $this->resend($outbox);
    }

    public function resend(ClosingReportOutbox $outbox): ClosingReportOutbox
    {
        if ($outbox->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            throw new BusinessRuleException('Laporan ini sudah terkirim ke BI.', 409);
        }

        $this->publish($outbox);

        return $outbox->fresh(['closingReport.outlet']);
    }

    /**
     * Susun ulang payload dari data laporan saat ini, lalu kembalikan baris ke
     * antrean — termasuk baris yang sudah terkirim.
     *
     * Hanya untuk pesan yang ditolak BI karena isinya salah (mis. `items: []`
     * dari builder lama). Bukan tombol di layar admin: mengirim ulang data yang
     * sudah sampai adalah permintaan BI, jadi jalurnya hanya lewat CLI
     * (`publish-pending --id=… --rebuild`). `messageId` tetap sama.
     */
    public function rebuild(ClosingReportOutbox $outbox): ClosingReportOutbox
    {
        $report = $outbox->closingReport;

        if (! $report) {
            throw new BusinessRuleException('Closing report untuk pesan ini tidak ditemukan.', 404);
        }

        $payload = $this->payloads->build($report);

        if ($payload['data']['items'] === []) {
            throw new BusinessRuleException('Laporan ini tidak punya menu untuk dikirim ke BI.', 422);
        }

        // Baris kiriman ulang tetap membawa `<id>.vN`-nya sendiri.
        $payload['messageId'] = $outbox->messageId();

        $outbox->update([
            'payload'         => $payload,
            'status'          => ClosingReportOutbox::STATUS_PENDING,
            'published_at'    => null,
            'last_error'      => null,
            'next_attempt_at' => null,
        ]);

        return $outbox;
    }

    /**
     * `messageId` kiriman ulang laporan ini dalam versi payload yang sedang
     * aktif: `<closingReportId>.v<versi>` — format yang disepakati BI
     * (docs/jawaban-bi-closing-report-v3.md). Tetap, tanpa waktu: menjalankan
     * replay dua kali tidak boleh menjadi dua revisi di BI.
     */
    public function replayMessageId(ClosingReport $report): string
    {
        return $report->id.'.v'.$this->payloads->version();
    }

    /**
     * Antre kiriman ulang satu laporan dengan payload yang disusun dari data
     * saat ini (harga master saat ini) dan `messageId` baru.
     *
     * Baris pesan submit tidak disentuh — riwayatnya tetap. Kalau kiriman
     * ulang untuk versi ini sudah pernah diantre, baris itu yang dikembalikan
     * (`wasRecentlyCreated` = false), tidak dibuat yang kedua.
     *
     * Hanya mengantre. Pemanggil yang mengirim lewat publish(); kalau gagal,
     * scheduler melanjutkan seperti pesan lain.
     *
     * @throws BusinessRuleException 422 kalau laporan tidak punya satu menu pun
     */
    public function enqueueReplay(ClosingReport $report): ClosingReportOutbox
    {
        $messageId = $this->replayMessageId($report);

        $existing = ClosingReportOutbox::where('message_id', $messageId)->first();

        if ($existing) {
            return $existing;
        }

        $payload = $this->payloads->build($report);

        if ($payload['data']['items'] === []) {
            throw new BusinessRuleException('Laporan ini tidak punya menu untuk dikirim ke BI.', 422);
        }

        $payload['messageId'] = $messageId;

        return ClosingReportOutbox::create([
            'closing_report_id' => $report->id,
            'message_id'        => $messageId,
            'payload'           => $payload,
            // Sama dengan enqueue(): pemanggil mengirim langsung, scheduler
            // baru boleh menyentuh baris ini setelah masa tenggang.
            'next_attempt_at'   => now()->addMinutes(self::IMMEDIATE_PUBLISH_GRACE_MINUTES),
        ]);
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
