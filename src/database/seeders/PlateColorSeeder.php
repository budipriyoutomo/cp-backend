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
            //
            // Baris ber-`brand_id` NULL ("belum ditetapkan") milik brand
            // tunggal itu diambil alih, bukan diduplikasi. Tanpa ini, run
            // pertama di DB kosong menulis warna tanpa brand (brand baru dibuat
            // OutletSeeder sesudahnya), dan run kedua membuat 9 warna lagi.
            $color = PlateColors::query()
                ->where('platename', $name)
                ->where('brand_id', $brandId)
                ->first()
                ?? self::unassigned($brandId, $name)
                ?? new PlateColors(['platename' => $name]);

            $color->fill([
                'brand_id'        => $brandId,
                'price'           => $price,
                'description'     => $name . ' plate',
                'target_foodcost' => 0,
                'is_active'       => true,
            ])->save();
        }
    }

    /**
     * Baris tanpa brand dengan nama sama — hanya dicari kalau ada brand
     * tunggal. Dengan dua brand atau lebih `soleBrandId()` mengembalikan NULL,
     * jadi tidak ada yang diambil alih diam-diam.
     */
    private static function unassigned(?string $brandId, string $name): ?PlateColors
    {
        if ($brandId === null) {
            return null;
        }

        return PlateColors::query()
            ->where('platename', $name)
            ->whereNull('brand_id')
            ->first();
    }
}