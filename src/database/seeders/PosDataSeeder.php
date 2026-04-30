<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PosDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('posdata')->truncate();

        $data = [];

        // 🔥 hanya 3 hari terakhir
        $dates = collect(range(0, 2))->map(fn($i) => now()->subDays($i)->toDateString());

        // 🔥 ambil production summary
        $productions = DB::table('production_items')
            ->selectRaw("
                plate_color,
                outlet_id,
                DATE(produced_at) as date,
                COUNT(*) as total_production
            ")
            ->whereDate('produced_at', '>=', now()->subDays(3))
            ->groupBy('plate_color', 'outlet_id', 'date')
            ->get();

        foreach ($productions as $p) {

            // 🔥 POS ≤ production
            $sold = rand(0, $p->total_production);

            $data[] = [
                'id' => Str::uuid(),
                'plate_color_id' => $p->plate_color,
                'outlet_id' => $p->outlet_id,
                'date' => $p->date,
                'sold' => $sold,

                'created_at' => now(),
                'updated_at' => now(),
                'created_by' => 'seeder',
                'updated_by' => 'seeder',
                'deleted_at' => null,
                'deleted_by' => null,
            ];
        }

        DB::table('posdata')->insert($data);
    }
}