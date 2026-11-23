<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu closing report boleh punya lebih dari satu pesan ke BI.
 *
 * Pesan submit memakai `messageId` = id laporan. Kiriman ulang dalam versi
 * payload baru (`closing-report:replay`) memakai `<id>.v<versi>` — dedup BI
 * berdasarkan `messageId` dan tidak menimpa apa pun, tapi BI mengunci laporan
 * pada `closingReportId`, jadi `messageId` baru menjadi revisi aktif, bukan
 * angka dobel (docs/jawaban-bi-closing-report-v3.md).
 *
 * Keunikan pindah dari `closing_report_id` ke `message_id`: itu yang menjamin
 * re-submit, request yang diputar ulang, maupun skrip replay yang dijalankan
 * dua kali tidak pernah mengantre pesan kedua dengan id yang sama.
 *
 * Kolomnya nullable hanya karena mengubah kolom butuh doctrine/dbal di
 * Laravel 10. Semua jalur penulisan mengisinya; baris lama di-backfill di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closing_report_outbox', function (Blueprint $table) {
            $table->string('message_id')->nullable()->after('closing_report_id');
        });

        DB::table('closing_report_outbox')
            ->whereNull('message_id')
            ->update(['message_id' => DB::raw('CAST(closing_report_id AS VARCHAR(64))')]);

        Schema::table('closing_report_outbox', function (Blueprint $table) {
            $table->unique('message_id');
            $table->dropUnique(['closing_report_id']);
            $table->index('closing_report_id');
        });
    }

    /**
     * Baris kiriman ulang dibuang dulu — tanpa itu unique lama tidak bisa
     * dipasang kembali.
     */
    public function down(): void
    {
        DB::table('closing_report_outbox')
            ->whereRaw('message_id <> CAST(closing_report_id AS VARCHAR(64))')
            ->delete();

        Schema::table('closing_report_outbox', function (Blueprint $table) {
            $table->dropIndex(['closing_report_id']);
            $table->unique('closing_report_id');
            $table->dropUnique(['message_id']);
            $table->dropColumn('message_id');
        });
    }
};
