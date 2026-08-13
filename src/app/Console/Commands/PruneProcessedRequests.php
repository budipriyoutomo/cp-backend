<?php

namespace App\Console\Commands;

use App\Models\ProcessedRequest;
use Illuminate\Console\Command;

class PruneProcessedRequests extends Command
{
    /**
     * @var string
     */
    protected $signature = 'idempotency:prune {--days=7 : Simpan record sebanyak N hari terakhir}';

    /**
     * @var string
     */
    protected $description = 'Hapus record idempotensi lama dari processed_requests (satu baris per mutasi sukses, berisi seluruh response body).';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Opsi --days harus minimal 1 — retensi 0 hari akan menghapus replay yang masih dipakai antrean offline.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Delete in chunks: a busy kitchen accumulates a lot of rows and each
        // one carries a longText response body.
        $deleted = 0;

        do {
            $batch = ProcessedRequest::where('created_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Prune selesai: {$deleted} record dihapus (lebih tua dari {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
