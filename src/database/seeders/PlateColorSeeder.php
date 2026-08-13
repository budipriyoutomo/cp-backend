<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PlateColors;
use Illuminate\Support\Str;

class PlateColorSeeder extends Seeder
{
    public function run(): void
    {
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
            PlateColors::updateOrCreate(
                ['platename' => $name],
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