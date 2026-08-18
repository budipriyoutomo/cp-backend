<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nama dan kode menu unik DI DALAM brand, bukan global.
 *
 * Dua brand memang boleh sama-sama punya "Salmon Nigiri" — itu keputusan yang
 * diambil, dan sejajar dengan `platename` di plate_colors.
 *
 * Sampai sekarang keunikan menu hanya dijaga oleh validasi di MenuRequest,
 * tidak ada apa pun di basis data. Itu persis celah yang membuat data bisa
 * menyimpang lewat seeder, migrasi, atau tinker. Bentuknya partial unique index
 * dengan alasan yang sama seperti migration 000300:
 *
 * - Baris soft-deleted dikecualikan (`WHERE deleted_at IS NULL`), supaya alur
 *   hapus-lalu-buat-lagi tetap sah.
 * - Baris ber-`brand_id` NULL tidak terikat, karena PostgreSQL menganggap baris
 *   dengan kolom NULL selalu berbeda. Itu memang yang diinginkan untuk data
 *   yang belum di-backfill.
 *
 * `menus.code` nullable, dan NULL juga tidak saling bertabrakan — menu lama
 * yang belum punya kode tidak menghalangi migration ini.
 */
return new class extends Migration
{
    private const NAME_INDEX = 'menus_brand_menuname_unique';
    private const CODE_INDEX = 'menus_brand_code_unique';

    public function up(): void
    {
        $this->assertNoDuplicatesWithinBrand('menuname');
        $this->assertNoDuplicatesWithinBrand('code');

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::NAME_INDEX . ' ON menus (brand_id, menuname) '
            . 'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::CODE_INDEX . ' ON menus (brand_id, code) '
            . 'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::NAME_INDEX);
        DB::statement('DROP INDEX IF EXISTS ' . self::CODE_INDEX);
    }

    /**
     * CREATE UNIQUE INDEX gagal dengan pesan SQL telanjang kalau sudah ada
     * duplikat. Sebutkan barisnya supaya operator tahu apa yang harus dibereskan.
     */
    private function assertNoDuplicatesWithinBrand(string $column): void
    {
        $duplicates = DB::table('menus')
            ->whereNull('deleted_at')
            ->whereNotNull('brand_id')
            ->whereNotNull($column)
            ->select('brand_id', $column, DB::raw('COUNT(*) as total'))
            ->groupBy('brand_id', $column)
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($row) => "{$row->$column} (brand_id={$row->brand_id}, {$row->total} baris)")
            ->implode(', ');

        throw new RuntimeException(
            "Migrasi dibatalkan: ada menu dengan {$column} sama di dalam satu brand. "
            . 'Gabungkan atau hapus salah satunya lalu jalankan ulang: ' . $detail
        );
    }
};
