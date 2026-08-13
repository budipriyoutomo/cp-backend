<?php

namespace Database\Seeders;

use App\Models\Outlet;
use Illuminate\Database\Seeder;

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
                ['name' => $name, 'brand' => $brand, 'is_active' => true]
            );

            $this->command?->info("Outlet siap: {$code} — {$name}");
        }
    }
}
