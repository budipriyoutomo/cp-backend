<?php

namespace Database\Seeders;

use App\Models\WasteCategory;
use Illuminate\Database\Seeder;

/**
 * Kategori waste bawaan. Safe to re-run: matched by code. Lingkungan yang
 * sudah live mendapatkannya dari migration 2026_10_13_000200 — seeder ini
 * untuk rebuild.
 */
class WasteCategorySeeder extends Seeder
{
    public function run(): void
    {
        WasteCategory::updateOrCreate(
            ['code' => WasteCategory::DEFAULT_CODE],
            [
                'category_name' => 'Default Waste',
                'description'   => 'Kategori bawaan untuk semua alasan waste',
                'sort_order'    => 0,
                'is_active'     => true,
            ]
        );
    }
}
