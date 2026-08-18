<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 — mengganti asumsi "platename unik global" dengan "unik di dalam brand".
 *
 * Sebelumnya keunikan platename hanya dijaga oleh validasi di PlateColorRequest,
 * tidak ada apa pun di level basis data. Sekarang dua brand memang BOLEH sama-sama
 * punya "Merah" dengan harga berbeda, tapi satu brand tidak boleh punya dua.
 *
 * Dibuat sebagai partial unique index (`WHERE deleted_at IS NULL`), bukan
 * `$table->unique()`, karena dua alasan:
 *
 * - Baris soft-deleted harus dikecualikan. Menghapus "Merah" lalu membuatnya
 *   lagi adalah operasi yang sah, dan unique index biasa akan menolaknya.
 * - Unique index biasa atas (brand_id, platename) tidak berguna selama brand_id
 *   masih NULL: di PostgreSQL baris dengan kolom NULL dianggap selalu berbeda,
 *   jadi duplikat tetap lolos. Perilaku itu justru yang diinginkan untuk data
 *   yang belum di-backfill — dan tetap berlaku di bentuk partial ini.
 *
 * PostgreSQL dan SQLite sama-sama mendukung sintaks ini.
 */
return new class extends Migration
{
    private const INDEX = 'plate_colors_brand_platename_unique';

    public function up(): void
    {
        $this->assertNoDuplicatesWithinBrand();

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDEX . ' ON plate_colors (brand_id, platename) '
            . 'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
    }

    /**
     * CREATE UNIQUE INDEX gagal dengan pesan SQL telanjang kalau sudah ada
     * duplikat. Sebutkan barisnya supaya operator tahu apa yang harus dibereskan.
     */
    private function assertNoDuplicatesWithinBrand(): void
    {
        $duplicates = DB::table('plate_colors')
            ->whereNull('deleted_at')
            ->whereNotNull('brand_id')
            ->select('brand_id', 'platename', DB::raw('COUNT(*) as total'))
            ->groupBy('brand_id', 'platename')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($row) => "{$row->platename} (brand_id={$row->brand_id}, {$row->total} baris)")
            ->implode(', ');

        throw new RuntimeException(
            'Migrasi dibatalkan: ada plate color dengan nama sama di dalam satu brand. '
            . 'Gabungkan atau hapus salah satunya lalu jalankan ulang: ' . $detail
        );
    }
};
