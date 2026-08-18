<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Outlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Outlets come from the environment, not from a hardcoded list — the outlet
 * `code` is the contract with the POS system (POSService matches on it), so a
 * guessed value would silently break ingest.
 *
 * Set BOOTSTRAP_OUTLETS in backend/src/.env as a comma separated list of
 * CODE:Name:Brand, for example:
 *
 *     BOOTSTRAP_OUTLETS="BDG:Bandung:Maharasa,JKT:Jakarta:Maharasa"
 *
 * Safe to re-run: outlets are matched by code.
 */
class OutletSeeder extends Seeder
{
    public function run(): void
    {
        $raw = trim((string) env('BOOTSTRAP_OUTLETS', ''));

        if ($raw === '') {
            $this->command?->warn(
                'OutletSeeder dilewati: BOOTSTRAP_OUTLETS belum diisi. '
                . 'Format: CODE:Nama:Brand, dipisah koma.'
            );

            return;
        }

        foreach (explode(',', $raw) as $entry) {
            $parts = array_map('trim', explode(':', $entry));

            $code  = $parts[0] ?? '';
            $name  = $parts[1] ?? $code;
            $brand = $parts[2] ?? 'Maharasa';

            if ($code === '') {
                continue;
            }

            Outlet::updateOrCreate(
                ['code' => $code],
                [
                    'name'      => $name,
                    'brand'     => $brand,
                    'brand_id'  => $this->brandIdFor($brand),
                    'is_active' => true,
                ]
            );

            $this->command?->info("Outlet siap: {$code} — {$name}");
        }
    }

    /**
     * Nama brand dicocokkan case-insensitive supaya "Maharasa" di
     * BOOTSTRAP_OUTLETS dan "maharasa" di BOOTSTRAP_BRANDS tidak menghasilkan
     * dua baris brand. Kalau belum ada, dibuat — seeder ini tidak boleh
     * meninggalkan outlet tanpa brand.
     */
    private function brandIdFor(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $existing = Brand::whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($name)])->first();

        if ($existing) {
            return $existing->id;
        }

        return Brand::create([
            'code'      => $this->uniqueCodeFor($name),
            'name'      => $name,
            'is_active' => true,
        ])->id;
    }

    /**
     * `brands.code` unik, sementara nama brand bebas — "Maha Rasa" dan
     * "Maharasa" menghasilkan kode yang sama. Beri akhiran angka kalau bentrok,
     * jangan biarkan seeder mati di tengah jalan.
     */
    private function uniqueCodeFor(string $name): string
    {
        $base = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $base = $base === '' ? 'BRAND' : Str::substr($base, 0, 40);

        $code = $base;
        $suffix = 1;

        while (Brand::where('code', $code)->exists()) {
            $code = $base . '-' . $suffix;
            $suffix++;
        }

        return $code;
    }
}
