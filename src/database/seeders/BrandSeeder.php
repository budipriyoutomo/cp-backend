<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * Brand datang dari environment, bukan daftar hardcode — sama alasannya dengan
 * OutletSeeder: `code` adalah kunci natural yang dipakai migrasi data Fase 2
 * untuk menempelkan menu & plate color lama ke brand yang benar. Menebak nilai
 * di sini berarti menebak ke mana data historis akan menempel.
 *
 * Isi BOOTSTRAP_BRANDS di backend/src/.env, daftar CODE:Nama dipisah koma:
 *
 *     BOOTSTRAP_BRANDS="MHR:Maharasa,KTR:Katsuri"
 *
 * Aman dijalankan ulang: brand dicocokkan lewat `code`.
 */
class BrandSeeder extends Seeder
{
    /**
     * Brand yang dipakai seeder master lain (PlateColorSeeder, MenuSeeder).
     *
     * Aturannya sama dengan yang dipakai di seluruh fitur ini: satu brand →
     * pakai itu; nol brand → NULL (masih jalan, terlihat semua outlet); dua atau
     * lebih → JANGAN menebak, karena data master di seeder itu milik satu brand
     * tertentu dan menempelkannya ke brand yang salah berarti menempelkan harga
     * yang salah.
     */
    public static function soleBrandId(): ?string
    {
        $ids = Brand::query()->limit(2)->pluck('id');

        return $ids->count() === 1 ? $ids->first() : null;
    }

    public static function warnIfAmbiguous(?Seeder $seeder, string $what): void
    {
        if (Brand::query()->count() > 1) {
            $seeder?->command?->warn(
                "{$what} diseed tanpa brand: ada lebih dari satu brand dan seeder tidak menebak. "
                . 'Barisnya terlihat semua outlet — tetapkan brand-nya lewat master.'
            );
        }
    }

    public function run(): void
    {
        $raw = trim((string) env('BOOTSTRAP_BRANDS', ''));

        if ($raw === '') {
            $this->command?->warn(
                'BrandSeeder dilewati: BOOTSTRAP_BRANDS belum diisi. '
                . 'Format: CODE:Nama, dipisah koma.'
            );

            return;
        }

        foreach (explode(',', $raw) as $entry) {
            $parts = array_map('trim', explode(':', $entry));

            $code = $parts[0] ?? '';
            $name = $parts[1] ?? $code;

            if ($code === '') {
                continue;
            }

            // NOTE: tanpa 'id' di payload. HasUuid mengisi id saat create; pada
            // update baris mempertahankan id lama, sehingga FK yang menunjuk ke
            // sini (mulai Fase 2) tidak putus saat seeder dijalankan ulang.
            Brand::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true]
            );

            $this->command?->info("Brand siap: {$code} — {$name}");
        }
    }
}
