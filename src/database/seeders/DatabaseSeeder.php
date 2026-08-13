<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The default `php artisan db:seed` target.
 *
 * It runs DEMO data: ProductionItemSeeder and PosDataSeeder invent transactions
 * and TRUNCATE their tables first. That is fine on a scratch database and
 * catastrophic anywhere else, so it refuses to run outside local/testing.
 *
 * For a real database — including a rebuild — use the master-data seeder:
 *
 *     php artisan db:seed --class=BootstrapSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DatabaseSeeder berisi data demo dan men-TRUNCATE production_items serta posdata. '
                . 'Untuk database sungguhan pakai: php artisan db:seed --class=BootstrapSeeder'
            );
        }

        $this->call([
            BootstrapSeeder::class,
        ]);
    }
}
