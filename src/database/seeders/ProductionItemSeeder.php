<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductionItem; 
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductionItemSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('production_items')->truncate();

        $menuMap = [];

        foreach (DB::table('menus')->select('id', 'plate_color_id')->get() as $m) {
            $menuMap[$m->plate_color_id][] = $m->id;
        }

        $outlets = DB::table('outlets')->pluck('id')->toArray();
        $plateColors = array_keys($menuMap);

        $batch = [];
        $batchSize = 200;

        for ($d = 0; $d < 3; $d++) {

            $date = now()->subDays($d)->toDateString();

            foreach ($outlets as $outletId) {
                foreach ($plateColors as $plateColorId) {

                    $menuIds = $menuMap[$plateColorId];

                    $producedQty = rand(20, 80);

                    // 🔥 tentukan rasio sold (biar realistis)
                    $soldTarget = rand(
                        (int)($producedQty * 0.6),
                        (int)($producedQty * 0.9)
                    );

                    for ($i = 0; $i < $producedQty; $i++) {

                        $menuId = $menuIds[array_rand($menuIds)];

                        $hour = rand(10, 22);
                        $minute = rand(0, 59);

                        $producedAt = "$date $hour:$minute:00";
                        $expiresAt = date('Y-m-d H:i:s', strtotime("$producedAt +60 minutes"));

                        $finalStatus = null;
                        $soldAt = null;
                        $wastedAt = null;

                        if ($i < $soldTarget) {
                            // ✅ SOLD
                            $finalStatus = 'sold';
                            $soldAt = date('Y-m-d H:i:s', strtotime("$producedAt +" . rand(5, 40) . " minutes"));
                        } else {
                            // ✅ WASTE
                            $finalStatus = 'wasted';
                            $wastedAt = $expiresAt;
                        }

                        // belt status (opsional, bisa sinkron dengan final_status)
                        $now = date('Y-m-d H:i:s');

                        if ($expiresAt <= $now) {
                            $beltStatus = 'expired';
                        } elseif ($expiresAt <= date('Y-m-d H:i:s', strtotime("$now +15 minutes"))) {
                            $beltStatus = 'warning';
                        } else {
                            $beltStatus = 'fresh';
                        }

                        $batch[] = [
                            'id' => (string) Str::uuid(),
                            'menu_id' => $menuId,
                            'outlet_id' => $outletId,
                            'plate_color' => $plateColorId,
                            'quantity' => 1,

                            'produced_at' => $producedAt,
                            'expires_at' => $expiresAt,

                            'belt_status' => $beltStatus,
                            'final_status' => $finalStatus,

                            'sold_at' => $soldAt,
                            'wasted_at' => $wastedAt,

                            'notes' => 'Generated production',

                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        if (count($batch) >= $batchSize) {
                            DB::table('production_items')->insert($batch);
                            $batch = [];
                            gc_collect_cycles();
                        }
                    }
                }
            }
        }

        if (!empty($batch)) {
            DB::table('production_items')->insert($batch);
        }
    }
}