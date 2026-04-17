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
        // Ambil data relasi
        $plateColors = DB::table('plate_colors')->pluck('id')->toArray();
        $outlets = DB::table('outlets')->pluck('id')->toArray();

        if (empty($plateColors) || empty($outlets)) {
            $this->command->warn('Plate colors / outlets kosong!');
            return;
        }

        $data = [];

        // generate data 30 hari terakhir
        for ($i = 0; $i < 30; $i++) {
            $date = Carbon::now()->subDays($i)->toDateString();

            foreach ($outlets as $outletId) {
                foreach ($plateColors as $plateColorId) {

                    $data[] = [
                        'id' => Str::uuid(),

                        'plate_color_id' => $plateColorId,
                        'outlet_id' => $outletId,

                        'date' => $date,
                        'sold' => rand(0, 100),

                        'created_at' => now(),
                        'updated_at' => now(),

                        // fullstamps (sesuaikan dengan project kamu)
                        'created_by' => 'seeder',
                        'updated_by' => 'seeder',
                        'deleted_at' => null,
                        'deleted_by' => null,
                    ];
                }
            }
        }

        DB::table('posdata')->insert($data);
    }
}