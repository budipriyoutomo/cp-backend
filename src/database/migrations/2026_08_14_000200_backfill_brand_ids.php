<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 2 — migrasi data.
 *
 * Sumber kebenaran satu-satunya untuk brand yang sudah ada adalah kolom teks
 * `outlets.brand`. Tidak ada tempat lain di basis data yang tahu menu atau
 * plate color milik brand mana.
 *
 * Aturan yang dipakai, dan alasannya:
 *
 * 1. Brand dibuat dari nilai distinct `outlets.brand`. Kalau `brands` sudah
 *    berisi baris dengan nama yang sama (mis. dari BrandSeeder), baris itu
 *    dipakai ulang — bukan dibuat ganda.
 * 2. `outlets.brand_id` diisi dengan mencocokkan nama. Tidak ambigu.
 * 3. `menus.brand_id` dan `plate_colors.brand_id` hanya diisi kalau di akhir
 *    langkah 1 hanya ADA SATU brand. Dengan dua brand atau lebih, tidak ada
 *    informasi apa pun di basis data yang bisa memberi tahu menu mana milik
 *    siapa — menebak berarti menempelkan harga brand A ke piring brand B.
 *    Dalam kasus itu kolomnya dibiarkan NULL dan operator diberi peringatan.
 *
 * Idempoten: hanya menyentuh baris yang `brand_id`-nya masih NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createBrandsFromOutletText();
        $this->linkOutlets();
        $this->linkMenusAndPlateColors();
    }

    /**
     * Tidak ada down(). Membalikkannya berarti mengosongkan brand_id, dan
     * migration 000100 sudah melakukan itu dengan men-drop kolomnya. Menulis
     * ulang di sini hanya menambah cara untuk kehilangan data.
     */
    public function down(): void
    {
        //
    }

    private function createBrandsFromOutletText(): void
    {
        $names = DB::table('outlets')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        foreach ($names as $name) {
            $exists = DB::table('brands')
                ->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($name)])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('brands')->insert([
                'id'         => (string) Str::uuid(),
                'code'       => $this->uniqueCodeFor($name),
                'name'       => $name,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function linkOutlets(): void
    {
        $brandsByName = DB::table('brands')
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [Str::lower(trim((string) $name)) => $id]);

        $outlets = DB::table('outlets')
            ->whereNull('brand_id')
            ->get(['id', 'code', 'brand']);

        $unresolved = [];

        foreach ($outlets as $outlet) {
            $key = Str::lower(trim((string) $outlet->brand));

            if ($key === '' || !isset($brandsByName[$key])) {
                $unresolved[] = $outlet->code;
                continue;
            }

            DB::table('outlets')
                ->where('id', $outlet->id)
                ->update(['brand_id' => $brandsByName[$key]]);
        }

        if ($unresolved !== []) {
            $this->warn(
                'Outlet berikut tidak punya nilai `brand` yang bisa dipetakan, brand_id dibiarkan NULL: '
                . implode(', ', $unresolved) . '. Set brand-nya lewat master outlet sebelum Fase 4.'
            );
        }
    }

    private function linkMenusAndPlateColors(): void
    {
        $brandIds = DB::table('brands')->pluck('id');

        if ($brandIds->isEmpty()) {
            // Basis data kosong atau baru — tidak ada yang perlu ditempelkan.
            return;
        }

        if ($brandIds->count() > 1) {
            $this->warn(
                'Ada ' . $brandIds->count() . ' brand, sehingga menus.brand_id dan plate_colors.brand_id '
                . 'DIBIARKAN NULL — tidak ada data yang bisa menentukan menu/warna mana milik brand mana. '
                . 'Tetapkan manual lewat master sebelum Fase 4 mulai menyaring per brand.'
            );

            return;
        }

        $brandId = $brandIds->first();

        DB::table('menus')->whereNull('brand_id')->update(['brand_id' => $brandId]);
        DB::table('plate_colors')->whereNull('brand_id')->update(['brand_id' => $brandId]);
    }

    /**
     * `brands.code` unik. Nama brand bebas, jadi kodenya diturunkan lalu diberi
     * akhiran angka kalau bentrok.
     */
    private function uniqueCodeFor(string $name): string
    {
        $base = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $base = $base === '' ? 'BRAND' : Str::substr($base, 0, 40);

        $code = $base;
        $suffix = 1;

        while (DB::table('brands')->where('code', $code)->exists()) {
            $code = $base . '-' . $suffix;
            $suffix++;
        }

        return $code;
    }

    private function warn(string $message): void
    {
        // Migration anonim tidak punya $this->command, jadi tulis langsung ke
        // output artisan supaya operator melihatnya saat deploy. STDERR hanya
        // ada di SAPI CLI — lewat php-fpm (migrate dari kode) jatuh ke log.
        if (defined('STDERR')) {
            fwrite(STDERR, PHP_EOL . '  [brand] ' . $message . PHP_EOL);
        }

        Log::warning('[brand] ' . $message);
    }
};
