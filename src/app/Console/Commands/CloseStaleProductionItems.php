<?php

namespace App\Console\Commands;

use App\Models\CloseDayLog;
use App\Services\Production\ProductionItemService;
use Illuminate\Console\Command;

class CloseStaleProductionItems extends Command
{
    /**
     * @var string
     */
    protected $signature = 'production:close-stale
        {--outlet= : Batasi ke satu outlet (UUID)}
        {--scheduled : Dipanggil scheduler — hanya membedakan jejak di close_day_logs}';

    /**
     * @var string
     */
    protected $description = 'Auto-waste plate yang belum diselesaikan dari hari-hari sebelumnya (atribusi ke hari produksinya).';

    public function handle(ProductionItemService $service): int
    {
        $outletId = $this->option('outlet') ?: null;

        $trigger = $this->option('scheduled')
            ? CloseDayLog::TRIGGER_SCHEDULER
            : CloseDayLog::TRIGGER_COMMAND;

        $count = $service->autoWasteCarryOver($outletId, $trigger);

        $this->info("Auto-waste selesai: {$count} plate sisa ditandai sebagai waste.");

        return self::SUCCESS;
    }
}
