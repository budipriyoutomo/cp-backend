<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kelanjutan dari 2026_09_11_000000: masalah yang sama, kolom yang terlewat.
 *
 * `closing_reports.submitted_by` bertipe `uuid`, padahal yang ditulis ke sana
 * adalah `auth()->id()` — angka, karena `users.id` auto-increment. Submit di
 * /operation/closing-report karena itu mati dengan pesan yang sama:
 *
 *   SQLSTATE[22P02]: invalid input syntax for type uuid: "1"
 *
 * Migration sebelumnya hanya menyisir `created_by`/`updated_by`/`deleted_by`,
 * yaitu kolom yang ditangani `HasUserstamps`. `submitted_by` diisi manual di
 * [ClosingReportService::submit()], jadi tidak ikut tersapu.
 *
 * Penyisiran ulang seluruh migration: inilah satu-satunya kolom penampung id
 * user yang tersisa di luar userstamp. `closing_report_entries` sudah memakai
 * macro `fullstamps()` dan tidak punya kolom `_by` tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite tidak punya tipe uuid, dan tidak mendukung ALTER COLUMN TYPE.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE closing_reports ALTER COLUMN submitted_by TYPE char(36) USING submitted_by::text'
        );
    }

    /**
     * Sama seperti migration sebelumnya: setelah aplikasi jalan, isi kolomnya
     * adalah angka dan `::uuid` akan gagal di tengah tabel tanpa menyebut baris
     * mana. Diperiksa dulu, lalu ditolak dengan pesan yang bisa ditindaklanjuti.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $bad = DB::table('closing_reports')
            ->whereNotNull('submitted_by')
            ->pluck('submitted_by', 'id')
            ->reject(fn ($value) => (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                trim((string) $value)
            ));

        if ($bad->isNotEmpty()) {
            $detail = $bad
                ->map(fn ($value, $id) => "{$id} (submitted_by=" . trim((string) $value) . ')')
                ->implode(', ');

            throw new RuntimeException(
                'Rollback dibatalkan: closing_reports.submitted_by berisi nilai yang bukan UUID, '
                . 'jadi tidak bisa dikembalikan ke tipe uuid. Baris berikut harus dibereskan dulu: ' . $detail
            );
        }

        DB::statement(
            'ALTER TABLE closing_reports ALTER COLUMN submitted_by TYPE uuid USING trim(submitted_by)::uuid'
        );
    }
};
