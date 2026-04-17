<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductionItem;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ProductionItemSeeder extends Seeder
{
    public function run(): void
    {
        // Optional: kosongkan dulu
        ProductionItem::truncate();

        $menus = Menu::pluck('id')->toArray();
        $outlets = Outlet::pluck('id')->toArray();
        $plateColors = PlateColors::pluck('id')->toArray();

        if (empty($menus) || empty($outlets) || empty($plateColors)) {
            $this->command->warn('❌ Menu / Outlet / PlateColor masih kosong!');
            return;
        }

        $data = [];

        for ($i = 0; $i < 100; $i++) {

            // Random waktu produksi (0 - 90 menit lalu)
            $producedAt = Carbon::now()->subMinutes(rand(0, 90));

            // Expired 60 menit setelah produksi
            $expiresAt = (clone $producedAt)->addMinutes(60);

            // Tentukan status berdasarkan waktu
            $now = now();

            if ($expiresAt <= $now) {
                $beltStatus = 'expired';
            } elseif ($expiresAt <= $now->copy()->addMinutes(15)) {
                $beltStatus = 'warning';
            } else {
                $beltStatus = 'fresh';
            }

            // Final status logic
            $finalStatus = null;
            $soldAt = null;
            $wastedAt = null;

            if ($beltStatus === 'expired') {
                $finalStatus = 'wasted';
                $wastedAt = $expiresAt;
            } else {
                // 70% kemungkinan terjual
                if (rand(1, 100) <= 70) {
                    $finalStatus = 'sold';
                    $soldAt = (clone $producedAt)->addMinutes(rand(5, 40));
                }
            }

            $data[] = [
                'id' => (string) Str::uuid(),
                'menu_id' => $menus[array_rand($menus)],
                'outlet_id' => $outlets[array_rand($outlets)],
                'plate_color' => $plateColors[array_rand($plateColors)],
                'quantity' => rand(1, 5),

                'produced_at' => $producedAt,
                'expires_at' => $expiresAt,

                'belt_status' => $beltStatus,
                'final_status' => $finalStatus,

                'sold_at' => $soldAt,
                'wasted_at' => $wastedAt,

                'notes' => 'Seeder generated',

                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ProductionItem::insert($data);
    }
}