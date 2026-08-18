<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Master data only — safe to run against a real database.
 *
 * This is what a rebuild needs: plate colors, menus, waste reasons, outlets,
 * and one admin to log in with. It deliberately excludes ProductionItemSeeder
 * and PosDataSeeder, which invent demo transactions and TRUNCATE their tables.
 *
 *     php artisan db:seed --class=BootstrapSeeder
 *
 * Every step is idempotent (matched by natural key), so re-running is safe.
 */
class BootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BrandSeeder::class,
            PlateColorSeeder::class,
            MenuSeeder::class,
            WasteReasonSeeder::class,
            OutletSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
