<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One sales header per outlet per day.
 *
 * ClosingReportService calls firstOrFail() on (outlet_id, date) assuming that,
 * but nothing enforced it — and POST /sales always inserted, so every save from
 * the Sales Input screen created another header for the same day. The closing
 * report then picked one of them arbitrarily.
 *
 * The index is partial (deleted_at IS NULL) so soft-deleted headers do not keep
 * occupying the slot. Both PostgreSQL and SQLite support partial indexes.
 */
return new class extends Migration
{
    private const INDEX = 'sales_headers_outlet_id_date_unique';

    public function up(): void
    {
        $this->softDeleteExistingDuplicates();

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDEX . ' ON sales_headers (outlet_id, date) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
    }

    /**
     * Existing duplicates would block the index. Keep one header per
     * (outlet_id, date) — a submitted one wins over a draft, and among equals
     * the most recently created wins — and soft-delete the rest. Nothing is
     * destroyed; the losers stay readable with withTrashed().
     */
    private function softDeleteExistingDuplicates(): void
    {
        if (!Schema::hasTable('sales_headers')) {
            return;
        }

        $duplicates = DB::table('sales_headers')
            ->select('outlet_id', 'date')
            ->whereNull('deleted_at')
            ->groupBy('outlet_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $rows = DB::table('sales_headers')
                ->where('outlet_id', $group->outlet_id)
                ->where('date', $group->date)
                ->whereNull('deleted_at')
                ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->pluck('id');

            $losers = $rows->slice(1)->all();

            if ($losers) {
                DB::table('sales_headers')
                    ->whereIn('id', $losers)
                    ->update(['deleted_at' => now()]);
            }
        }
    }
};
