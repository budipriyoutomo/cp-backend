<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the stored `production_items.belt_status` in step with `expires_at`.
 *
 * The column is a cached derivative — reads that care about accuracy to the
 * second (conveyor, expired list) compute it from `expires_at` instead. This
 * exists so anything reading the column directly, like the dashboard's
 * "expiring soon" count, is not stale.
 *
 * It used to run as a side effect of GET /production/conveyor, which meant every
 * tablet triggered two mass UPDATEs every 30 seconds.
 */
class RefreshBeltStatus extends Command
{
    /**
     * @var string
     */
    protected $signature = 'production:refresh-belt-status {--outlet= : Batasi ke satu outlet (UUID)}';

    /**
     * @var string
     */
    protected $description = 'Segarkan belt_status (fresh/warning/expired) dari expires_at untuk plate yang belum difinalisasi.';

    public function handle(): int
    {
        $outletId = $this->option('outlet') ?: null;
        $now      = now();

        $expired = $this->base($outletId)
            ->where('expires_at', '<=', $now)
            ->where('belt_status', '!=', 'expired')
            ->update(['belt_status' => 'expired']);

        $warning = $this->base($outletId)
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->copy()->addMinutes(15))
            ->where('belt_status', '!=', 'warning')
            ->update(['belt_status' => 'warning']);

        $fresh = $this->base($outletId)
            ->where('expires_at', '>', $now->copy()->addMinutes(15))
            ->where('belt_status', '!=', 'fresh')
            ->update(['belt_status' => 'fresh']);

        $this->info("Belt status disegarkan: {$expired} expired, {$warning} warning, {$fresh} fresh.");

        return self::SUCCESS;
    }

    private function base(?string $outletId)
    {
        $query = DB::table('production_items')
            ->whereNull('final_status')
            ->whereNull('deleted_at');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        return $query;
    }
}
