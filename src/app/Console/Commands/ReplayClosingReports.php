<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Models\Outlet;
use App\Services\ClosingReport\ClosingReportOutboxService;
use App\Services\ClosingReport\ClosingReportPayloadBuilder;
use App\Support\Uuid;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Kirim ulang closing report yang sudah disubmit dalam versi payload yang
 * sedang aktif, dengan `messageId` baru `<closingReportId>.v<versi>`.
 *
 * Untuk data historis setelah versi payload naik (mis. harga v3 sejak
 * 1 Oktober 2026). BI menyimpan pesan ini sebagai revisi aktif laporan yang
 * sama — dikunci pada `closingReportId`, jadi tidak ada angka dobel
 * (docs/jawaban-bi-closing-report-v3.md).
 *
 * Beda dengan `publish-pending --id --rebuild`: itu menimpa pesan lama dengan
 * `messageId` yang sama, dan BI membuangnya sebagai duplikat.
 */
class ReplayClosingReports extends Command
{
    /**
     * @var string
     */
    protected $signature = 'closing-report:replay
                            {--from= : Tanggal laporan paling awal (Y-m-d)}
                            {--to= : Tanggal laporan paling akhir (Y-m-d), default hari ini}
                            {--outlet= : Hanya outlet dengan kode ini}
                            {--report= : Satu closing report saja (UUID) — untuk pesan contoh ke BI}
                            {--revision=1 : Nomor revisi kiriman ulang. 1 = <id>.v3; 2 ke atas = <id>.v3.r<n>, untuk laporan yang kiriman ulangnya sudah tersimpan di BI}
                            {--dry-run : Tampilkan apa yang akan dikirim, tanpa mengirim atau menulis apa pun}';

    /**
     * @var string
     */
    protected $description = 'Kirim ulang closing report lampau ke BI dalam versi payload saat ini, dengan messageId baru.';

    public function handle(ClosingReportOutboxService $service, ClosingReportPayloadBuilder $payloads): int
    {
        $version = $payloads->version();

        if ($version < ClosingReportPayloadBuilder::VERSION_2) {
            $this->error('Versi payload aktif masih v1 — tidak ada yang perlu dikirim ulang. '
                .'Set RABBITMQ_CLOSING_REPORT_PAYLOAD_VERSION=3 lalu restart container (config di-cache saat start).');

            return self::FAILURE;
        }

        $revision = $this->revision();

        if ($revision === null) {
            return self::FAILURE;
        }

        $reports = $this->reports();

        if ($reports === null) {
            return self::FAILURE;
        }

        if ($reports->isEmpty()) {
            $this->info('Tidak ada closing report submitted yang cocok.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            '%d closing report, payload v%d%s%s.',
            $reports->count(),
            $version,
            $revision > 1 ? ", revisi {$revision}" : '',
            $dryRun ? ' (dry run, tidak ada yang dikirim)' : '',
        ));

        $counts = ['sent' => 0, 'already' => 0, 'empty' => 0, 'failed' => 0];

        foreach ($reports as $index => $report) {
            $outcome = $dryRun
                ? $this->preview($service, $payloads, $report, $revision)
                : $this->replay($service, $report, $revision);

            $counts[$outcome]++;

            if ($outcome === 'failed') {
                $remaining = $reports->count() - $index - 1;

                if ($remaining > 0) {
                    $this->warn("Berhenti: broker menolak. {$remaining} laporan belum diproses — jalankan ulang perintah yang sama setelah broker pulih; yang sudah terkirim dilewati.");
                }

                break;
            }
        }

        $this->info(sprintf(
            '%s: %d %s, %d sudah terkirim sebelumnya, %d dilewati (tanpa menu), %d gagal.',
            $dryRun ? 'Dry run' : 'Selesai',
            $counts['sent'],
            $dryRun ? 'akan dikirim' : 'terkirim',
            $counts['already'],
            $counts['empty'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, ClosingReport>|null null kalau opsinya tidak sah
     */
    private function reports(): ?Collection
    {
        $query = ClosingReport::query()
            ->where('status', 'submitted')
            ->with('outlet')
            ->orderBy('date')
            ->orderBy('outlet_id');

        if ($id = $this->option('report')) {
            if (! Uuid::matches($id)) {
                $this->error("--report harus UUID, diterima: {$id}");

                return null;
            }

            return $query->whereKey($id)->get();
        }

        $from = $this->dateOption('from');

        if (! $from) {
            $this->error('Isi --from=Y-m-d (atau --report=<uuid> untuk satu laporan).');

            return null;
        }

        $to = $this->option('to') ? $this->dateOption('to') : now()->toDateString();

        if (! $to) {
            return null;
        }

        if ($from > $to) {
            $this->error("--from ({$from}) lebih besar dari --to ({$to}).");

            return null;
        }

        $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        if ($code = $this->option('outlet')) {
            $outlet = Outlet::where('code', $code)->first();

            if (! $outlet) {
                $this->error("Outlet dengan kode {$code} tidak ditemukan.");

                return null;
            }

            $query->where('outlet_id', $outlet->id);
        }

        return $query->get();
    }

    /**
     * @return int|null null kalau bukan bilangan bulat >= 1
     */
    private function revision(): ?int
    {
        $value = (string) $this->option('revision');

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error("--revision harus bilangan bulat 1 atau lebih, diterima: {$value}");

            return null;
        }

        return (int) $value;
    }

    private function dateOption(string $name): ?string
    {
        $value = (string) $this->option($name);

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            if ($value !== '') {
                $this->error("--{$name} harus berformat Y-m-d, diterima: {$value}");
            }

            return null;
        }

        return $value;
    }

    private function preview(ClosingReportOutboxService $service, ClosingReportPayloadBuilder $payloads, ClosingReport $report, int $revision): string
    {
        $messageId = $service->replayMessageId($report, $revision);
        $existing = ClosingReportOutbox::where('message_id', $messageId)->first();

        if ($existing?->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            $this->line($this->row($report, $messageId, count($existing->payload['data']['items'] ?? []), 'sudah terkirim'));

            return 'already';
        }

        $items = $existing
            ? count($existing->payload['data']['items'] ?? [])
            : count($payloads->build($report)['data']['items']);

        if ($items === 0) {
            $this->line($this->row($report, $messageId, 0, 'dilewati: tanpa menu'));

            return 'empty';
        }

        $this->line($this->row($report, $messageId, $items, $existing ? 'akan dikirim (sudah antre)' : 'akan dikirim'));

        return 'sent';
    }

    private function replay(ClosingReportOutboxService $service, ClosingReport $report, int $revision): string
    {
        try {
            $row = $service->enqueueReplay($report, $revision);
        } catch (BusinessRuleException) {
            $this->line($this->row($report, $service->replayMessageId($report, $revision), 0, 'dilewati: tanpa menu'));

            return 'empty';
        }

        $items = count($row->payload['data']['items'] ?? []);

        if ($row->status === ClosingReportOutbox::STATUS_PUBLISHED) {
            $this->line($this->row($report, $row->messageId(), $items, 'sudah terkirim'));

            return 'already';
        }

        if ($service->publish($row)) {
            $this->line($this->row($report, $row->messageId(), $items, 'terkirim'));

            return 'sent';
        }

        $this->line($this->row($report, $row->messageId(), $items, 'GAGAL: '.$row->fresh()->last_error));

        return 'failed';
    }

    private function row(ClosingReport $report, string $messageId, int $items, string $result): string
    {
        return sprintf(
            '%s  %-10s  %s  items %d  %s',
            $report->date->toDateString(),
            $report->outlet?->code ?? '-',
            $messageId,
            $items,
            $result,
        );
    }
}
