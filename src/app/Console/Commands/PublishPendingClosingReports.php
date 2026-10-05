<?php

namespace App\Console\Commands;

use App\Models\ClosingReportOutbox;
use App\Services\ClosingReport\ClosingReportOutboxService;
use App\Support\Uuid;
use Illuminate\Console\Command;

/**
 * Kirim ulang pesan `closingreport.submitted` yang belum sampai ke BI.
 *
 * Jalan tiap menit lewat scheduler. Penyebab umum: broker mati saat submit,
 * atau queue BI belum di-bind ke exchange (pesan dikembalikan broker).
 */
class PublishPendingClosingReports extends Command
{
    /**
     * @var string
     */
    protected $signature = 'closing-report:publish-pending
                            {--id= : Kirim satu baris outbox saja (UUID), meski sudah lewat batas atau belum jatuh tempo}
                            {--limit=100 : Maksimal baris per putaran}
                            {--dry-run : Tampilkan apa yang akan dikirim, tanpa mengirim}';

    /**
     * @var string
     */
    protected $description = 'Kirim ulang pesan closing report ke RabbitMQ yang tertunda.';

    public function handle(ClosingReportOutboxService $service): int
    {
        $maxAttempts = (int) config('rabbitmq.closing_report.max_attempts');

        if ($id = $this->option('id')) {
            return $this->publishOne($service, $id);
        }

        $rows = ClosingReportOutbox::due($maxAttempts)
            ->limit(max((int) $this->option('limit'), 1))
            ->get();

        $exhausted = ClosingReportOutbox::query()
            ->where('status', '!=', ClosingReportOutbox::STATUS_PUBLISHED)
            ->where('attempts', '>=', $maxAttempts)
            ->count();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada pesan closing report yang perlu dikirim.');
            $this->warnExhausted($exhausted);

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($rows as $row) {
                $this->line("{$row->id}  report {$row->closing_report_id}  percobaan {$row->attempts}  {$row->last_error}");
            }

            $this->info("{$rows->count()} pesan menunggu (dry run, tidak ada yang dikirim).");
            $this->warnExhausted($exhausted);

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $service->publish($row) ? $ok++ : $failed++;
        }

        $this->info("Publish closing report: {$ok} terkirim, {$failed} gagal.");
        $this->warnExhausted($exhausted);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function publishOne(ClosingReportOutboxService $service, string $id): int
    {
        if (! Uuid::matches($id)) {
            $this->error("--id harus UUID, diterima: {$id}");

            return self::FAILURE;
        }

        $row = ClosingReportOutbox::find($id);

        if (! $row) {
            $this->error("Baris outbox {$id} tidak ditemukan.");

            return self::FAILURE;
        }

        if ($row->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            $this->info("Baris {$id} sudah terkirim pada {$row->published_at}, tidak dikirim ulang.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$row->id}  report {$row->closing_report_id}  percobaan {$row->attempts}  {$row->last_error}");
            $this->info('1 pesan menunggu (dry run, tidak ada yang dikirim).');

            return self::SUCCESS;
        }

        // Sama dengan tombol di layar admin — satu aturan, satu tempat.
        $row = $service->resend($row);

        if ($row->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            $this->info("Baris {$id} terkirim.");

            return self::SUCCESS;
        }

        $this->error("Baris {$id} gagal: {$row->last_error}");

        return self::FAILURE;
    }

    private function warnExhausted(int $count): void
    {
        if ($count > 0) {
            $this->warn("{$count} pesan berhenti dicoba (melewati batas percobaan). Kirim manual dengan --id setelah penyebabnya beres.");
        }
    }
}
