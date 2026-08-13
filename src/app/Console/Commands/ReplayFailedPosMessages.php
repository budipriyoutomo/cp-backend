<?php

namespace App\Console\Commands;

use App\Models\FailedPosMessage;
use App\Services\POSService;
use Illuminate\Console\Command;

/**
 * Replays POS messages the consumer could not process.
 *
 * Typical cause: a plate color or outlet was renamed, so storeFromEvent() could
 * not map the payload. Fix the master data, then run this.
 */
class ReplayFailedPosMessages extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pos:replay-failed
                            {--id= : Ulangi satu record saja (UUID)}
                            {--dry-run : Tampilkan apa yang akan diproses, tanpa menulis}';

    /**
     * @var string
     */
    protected $description = 'Proses ulang pesan POS yang gagal (mis. setelah nama plate color/outlet diperbaiki).';

    public function handle(POSService $service): int
    {
        $query = FailedPosMessage::query()->unresolved()->orderBy('created_at');

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $messages = $query->get();

        if ($messages->isEmpty()) {
            $this->info('Tidak ada pesan POS gagal yang perlu diproses.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($messages as $message) {
                $this->line("{$message->id}  percobaan {$message->attempts}  {$message->error}");
            }

            $this->info("{$messages->count()} pesan menunggu (dry run, tidak ada yang ditulis).");

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($messages as $message) {
            $data = $message->eventData();

            if ($data === null) {
                $message->increment('attempts');
                $message->update(['error' => 'Payload tidak berisi blok data yang valid']);
                $failed++;

                continue;
            }

            try {
                $service->storeFromEvent($data);

                $message->update(['resolved_at' => now()]);
                $ok++;
            } catch (\Throwable $e) {
                $message->increment('attempts');
                $message->update(['error' => $e->getMessage()]);
                $failed++;
            }
        }

        $this->info("Replay selesai: {$ok} berhasil, {$failed} masih gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
