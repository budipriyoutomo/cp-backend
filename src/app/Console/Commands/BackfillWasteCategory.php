<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Services\Production\WasteCategoryBackfillService;
use Illuminate\Console\Command;

class BackfillWasteCategory extends Command
{
    /**
     * @var string
     */
    protected $signature = 'waste:backfill-category
        {--dry-run : Tampilkan hasil pencocokan tanpa menulis apa pun}
        {--fallback= : Kode kategori untuk teks yang tidak cocok dengan master (mis. DEFAULT_WASTE)}';

    /**
     * @var string
     */
    protected $description = 'Isi kategori waste_records lama dari teks reason-nya. Baris yang sudah berkategori tidak disentuh.';

    public function handle(WasteCategoryBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $service->run($dryRun, $this->option('fallback'));
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($result['total'] === 0) {
            $this->info('Tidak ada waste_records tanpa kategori.');
            return self::SUCCESS;
        }

        $this->table(
            ['Teks reason', 'Baris', 'Kategori'],
            array_map(fn ($row) => [
                $row['reason'],
                $row['count'],
                $row['category'] ?? '— tidak cocok —',
            ], $result['byText'])
        );

        $verb = $dryRun ? 'akan diisi' : 'diisi';

        $this->line("Total tanpa kategori : {$result['total']}");
        $this->line("Cocok dengan master  : {$result['matched']} ({$verb})");
        $this->line("Pakai --fallback     : {$result['fallback']} ({$verb})");
        $this->line("Tetap kosong         : {$result['unmatched']}");

        if ($dryRun) {
            $this->warn('Dry run — tidak ada yang ditulis.');
        }

        return self::SUCCESS;
    }
}
