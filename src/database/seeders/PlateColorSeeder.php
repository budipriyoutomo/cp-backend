<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PlateColors;
use Illuminate\Support\Str;

class PlateColorSeeder extends Seeder
{
    public function run(): void
    {
        $brandId = BrandSeeder::soleBrandId();
        BrandSeeder::warnIfAmbiguous($this, 'Plate color');

        $data = [
            ['WHITE', 23000],
            ['BLUE', 30000],
            ['YELLOW', 32000],
            ['PINK', 38000],
            ['SILVER', 42000],
            ['BLACK', 47000],
            ['RED', 53000],
            ['GOLD', 60000],
            ['CHOCO M', 64000],
        ];

        foreach ($data as [$name, $price]) {
            // NOTE: no 'id' here. It used to be in the update payload, so a
            // second run tried to rewrite the primary key of an existing row
            // and broke the foreign keys pointing at it. HasUuid assigns the id
            // on create; on update the row keeps the one it already has.
            //
            // `brand_id` ikut jadi kunci pencocokan, bukan cuma nilai yang
            // ditulis. Tanpa itu, dijalankan ulang di basis data dua-brand akan
            // menemukan "WHITE" milik brand mana pun yang kebetulan lebih dulu
            // lalu menimpa harganya.
            PlateColors::updateOrCreate(
                ['platename' => $name, 'brand_id' => $brandId],
                [
                    'price' => $price,
                    'description' => $name . ' plate',
                    'target_foodcost' => 0,
                    'is_active' => true,
                ]
            );
        }
    }
}