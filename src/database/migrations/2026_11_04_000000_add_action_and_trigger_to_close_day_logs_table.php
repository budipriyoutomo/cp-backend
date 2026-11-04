<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `close_day_logs` kini juga mencatat auto-waste (`autoWasteCarryOver()`), bukan
 * hanya tombol Tutup Hari — keduanya cara piring sisa hari itu ditutup massal,
 * dan audit perlu melihat keduanya di satu linimasa.
 *
 * - `action`  — `close_day` (sisa → sold) / `auto_waste` (sisa hari lalu → waste).
 * - `trigger` — `button` (layar conveyor), `scheduler` (00:05), `command`
 *               (artisan manual), `get_data_pos` (gerbang Get Data POS).
 *
 * Default-nya mengisi baris lama: sebelum migration ini hanya tombol yang dicatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('close_day_logs', function (Blueprint $table) {
            $table->string('action', 20)->default('close_day')->after('outlet_id');
            $table->string('trigger', 20)->default('button')->after('action');

            $table->index(['outlet_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::table('close_day_logs', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'action']);
            $table->dropColumn(['action', 'trigger']);
        });
    }
};
