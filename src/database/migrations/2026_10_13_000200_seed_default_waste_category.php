<?php

use App\Models\WasteCategory;
use App\Models\WasteReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data awal kategori waste untuk lingkungan yang sudah berisi data. Deploy
 * tidak menjalankan seeder, jadi tanpa langkah ini halaman reason langsung
 * penuh baris "belum dikategorikan" dan close-stale tidak menemukan reason
 * sistemnya.
 *
 * - Membuat kategori `DEFAULT_WASTE` kalau belum ada.
 * - Membuat reason sistem "Auto-waste" dan "Import backdate" kalau belum ada,
 *   atau menandai yang sudah ada (nama sama) sebagai sistem.
 * - Memetakan setiap reason yang belum punya kategori ke `DEFAULT_WASTE`.
 *   Reason yang sudah punya kategori tidak disentuh.
 *
 * Idempoten — aman dijalankan ulang. `waste_records` lama TIDAK diisi di sini:
 * tabelnya bisa besar, dan pencocokan teksnya perlu dilihat dulu. Itu tugas
 * `php artisan waste:backfill-category --dry-run`.
 *
 * Tidak ada down(): kolom dan tabelnya sudah dibuang down() milik 000000/000100.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $categoryId = DB::table('waste_categories')
            ->where('code', WasteCategory::DEFAULT_CODE)
            ->value('id');

        if (!$categoryId) {
            $categoryId = (string) Str::uuid();

            DB::table('waste_categories')->insert([
                'id'            => $categoryId,
                'code'          => WasteCategory::DEFAULT_CODE,
                'category_name' => 'Default Waste',
                'description'   => 'Kategori bawaan untuk semua alasan waste',
                'sort_order'    => 0,
                'is_active'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        foreach (WasteReason::SYSTEM_REASONS as $name => $description) {
            $existing = DB::table('waste_reasons')->where('reason_name', $name)->first();

            if ($existing) {
                DB::table('waste_reasons')->where('id', $existing->id)->update([
                    'is_system'  => true,
                    'deleted_at' => null,
                    'is_active'  => true,
                    'updated_at' => $now,
                ]);
                continue;
            }

            DB::table('waste_reasons')->insert([
                'id'                => (string) Str::uuid(),
                'reason_name'       => $name,
                'description'       => $description,
                'waste_category_id' => $categoryId,
                'is_active'         => true,
                'is_system'         => true,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

        DB::table('waste_reasons')
            ->whereNull('waste_category_id')
            ->update(['waste_category_id' => $categoryId, 'updated_at' => $now]);
    }
};
