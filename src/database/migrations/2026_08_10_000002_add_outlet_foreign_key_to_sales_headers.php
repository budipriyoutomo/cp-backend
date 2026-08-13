<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sales_headers.outlet_id had no foreign key, so nothing stopped a header from
 * pointing at an outlet that does not exist.
 *
 * The column was created as `string` while outlets.id is `uuid`. PostgreSQL
 * refuses a foreign key across those types ("Key columns are of incompatible
 * types: character varying and uuid"), so the column is converted first.
 * SQLite is loosely typed and needs no conversion — which is exactly why the
 * test suite did not catch this.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoOrphanHeaders();

        if (DB::getDriverName() === 'pgsql') {
            $this->assertAllOutletIdsAreUuids();

            DB::statement('ALTER TABLE sales_headers ALTER COLUMN outlet_id TYPE uuid USING outlet_id::uuid');
        }

        Schema::table('sales_headers', function (Blueprint $table) {
            $table->foreign('outlet_id')->references('id')->on('outlets');
        });
    }

    public function down(): void
    {
        Schema::table('sales_headers', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_headers ALTER COLUMN outlet_id TYPE varchar(255) USING outlet_id::text');
        }
    }

    /**
     * Adding the constraint fails outright if orphans exist. Say which rows are
     * the problem instead of leaving the operator with a bare SQL error.
     */
    private function assertNoOrphanHeaders(): void
    {
        $orphans = DB::table('sales_headers')
            ->whereNotNull('outlet_id')
            ->whereNotIn('outlet_id', DB::table('outlets')->pluck('id'))
            ->get(['id', 'outlet_id', 'date']);

        if ($orphans->isEmpty()) {
            return;
        }

        $detail = $orphans
            ->map(fn ($r) => "{$r->id} (outlet_id={$r->outlet_id}, date={$r->date})")
            ->implode(', ');

        throw new RuntimeException(
            'Migrasi dibatalkan: ada sales_headers yang menunjuk outlet tidak dikenal. '
            . 'Perbaiki atau hapus baris berikut lalu jalankan ulang: ' . $detail
        );
    }

    /**
     * `outlet_id::uuid` throws on any value that is not UUID-shaped, and it
     * throws halfway through the table. Check first so the failure names the row.
     */
    private function assertAllOutletIdsAreUuids(): void
    {
        $bad = DB::table('sales_headers')
            ->whereNotNull('outlet_id')
            ->pluck('outlet_id', 'id')
            ->reject(fn ($outletId) => (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                (string) $outletId
            ));

        if ($bad->isEmpty()) {
            return;
        }

        $detail = $bad->map(fn ($outletId, $id) => "{$id} (outlet_id={$outletId})")->implode(', ');

        throw new RuntimeException(
            'Migrasi dibatalkan: ada sales_headers.outlet_id yang bukan UUID, jadi tidak bisa dikonversi. '
            . 'Perbaiki baris berikut lalu jalankan ulang: ' . $detail
        );
    }
};
