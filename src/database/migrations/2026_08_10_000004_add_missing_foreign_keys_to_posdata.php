<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * posdata looks like it has foreign keys but never did.
 *
 * The original migration wrote:
 *     $table->uuid('plate_color_id')->constrained('plate_colors')->nullOnDelete();
 *
 * `constrained()` only does anything on foreignId()/foreignUuid(). On a plain
 * uuid() column it falls through __call and sets an attribute nobody reads, so
 * no constraint was ever created. POS rows could reference a deleted plate
 * color or outlet, and storeFromEvent() maps by name — exactly the case where a
 * rename leaves dangling references.
 *
 * Both columns are already uuid, so no type conversion is needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoOrphans('plate_color_id', 'plate_colors');
        $this->assertNoOrphans('outlet_id', 'outlets');

        Schema::table('posdata', function (Blueprint $table) {
            $table->foreign('plate_color_id')->references('id')->on('plate_colors');
            $table->foreign('outlet_id')->references('id')->on('outlets');
        });
    }

    public function down(): void
    {
        Schema::table('posdata', function (Blueprint $table) {
            $table->dropForeign(['plate_color_id']);
            $table->dropForeign(['outlet_id']);
        });
    }

    private function assertNoOrphans(string $column, string $parentTable): void
    {
        $orphans = DB::table('posdata')
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table($parentTable)->pluck('id'))
            ->pluck($column, 'id');

        if ($orphans->isEmpty()) {
            return;
        }

        $detail = $orphans->map(fn ($v, $id) => "{$id} ({$column}={$v})")->implode(', ');

        throw new RuntimeException(
            "Migrasi dibatalkan: ada posdata dengan {$column} yang tidak ada di {$parentTable}. "
            . 'Perbaiki atau hapus baris berikut lalu jalankan ulang: ' . $detail
        );
    }
};
