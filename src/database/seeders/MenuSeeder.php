<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Menu;
use App\Models\PlateColors;
use Illuminate\Support\Str;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menus = [
            ['16022','Inari Sushi','WHITE',23000],
            ['10020','Tamagoyaki','BLUE',30000],
            ['10002','Edamame','BLUE',30000],
            ['16005','Tako Sushi','BLUE',30000],
            ['16013','Ika Sushi','BLUE',30000],
            ['16040','Aburi Ika Sushi','BLUE',30000],
            ['16048','Kani Mentai Sushi','BLUE',30000],
            ['16056','Inari Kanimayo','BLUE',30000],
            ['16057','Shima aji Aburi Sushi','BLUE',30000],
            ['16058','Shima aji Sushi','BLUE',30000],
            ['18014','Kani Kama Maki','BLUE',30000],
            ['18019','Tuna Salad Maki','BLUE',30000],
            ['18022','Kani Tamago Maki','BLUE',30000],
            ['16019','Salmon Sushi','BLUE',30000],
            ['16064','Tamago Mentai Sushi','BLUE',30000],
            ['16009','Maguro Sushi','YELLOW',32000],
            ['16049','Inari Tuna Salad','YELLOW',32000],
            ['18008','Tekka Maki','YELLOW',32000],
            ['18059','Crispy Cheese Roll','YELLOW',32000],
            ['16012','Salmon Belly Sushi','PINK',38000],
            ['16026','Aburi Slm Belly Sushi','PINK',38000],
            ['18006','Salmon Maki','PINK',38000],
            ['10005','Kanimayo Chinmi','PINK',38000],
            ['10008','Chuka Chinmi Chinmi','PINK',38000],
            ['16015','Ebi Mentai Sushi','PINK',38000],
            ['32446','Kani Tempura Floss Roll','PINK',38000],
            ['18009','Ebi Tempura Maki','PINK',38000],
            ['16007','Salmon Mentai Sushi','PINK',38000],
            ['18015','Kanimayo Tobiko Maki','PINK',38000],
            ['0ST00070001','Aburi Hirame Mentai Sushi','PINK',38000],
            ['16066','Spicy Salmon Inari Sushi','PINK',38000],
            ['16065','Smoked Salmon Inari Sushi','PINK',38000],
            ['11061','Lava Roll','SILVER',42000],
            ['18020','Mini California Maki','SILVER',42000],
            ['32447','Special Salmon Cheese Sushi','SILVER',42000],
            ['16003','Hamachi Sushi','SILVER',42000],
            ['16021','Nama Hotate Sushi','SILVER',42000],
            ['16034','Aburi Hamachi Sushi','SILVER',42000],
            ['16038','Aburi Nama Hotate Sushi','SILVER',42000],
            ['16061','Inari Lobster Salad','SILVER',42000],
            ['11017','Stamina Roll','SILVER',42000],
            ['32445','Spicy Tuna Salad Tobikko Roll','SILVER',42000],
            ['18056','Kani Mentai Mayo Roll','SILVER',42000],
            ['18001','Ebiten Floss Maki','SILVER',42000],
            ['10011','Chuka Kurage Chinmi','BLACK',47000],
            ['18004','Spicy Salmon Maki','BLACK',47000],
            ['18057','Karaage Roll','BLACK',47000],
            ['10014','Chuka Wakame Chinmi','BLACK',47000],
            ['10027','Lobster Salad Chinmi','BLACK',47000],
            ['16062','Aburi Nama Hotate Mentai','BLACK',47000],
            ['32608','Volcano Roll','BLACK',47000],
            ['18025','Tempura Maki Ebikko','BLACK',47000],
            ['20041','Salmon Kama Mentai','BLACK',47000],
            ['20048','Spicy Haruyaki','BLACK',47000],
            ['0ST00070002','Jo Unagi SH (1 pcs)','BLACK',47000],
            ['0ST00300014','Salmon Kama Karaage','BLACK',47000],
            ['11013','Crispy Roll','RED',53000],
            ['11037','Fuji Roll','RED',53000],
            ['18013','Baked Salmon Maki','RED',53000],
            ['18017','Salmon Skin Maki','RED',53000],
            ['18046','Spicy Crispy Roll','RED',53000],
            ['14003','Maguro Sashimi','RED',53000],
            ['18002','Soft Shel Crb Maki','GOLD',60000],
            ['18050','Salmon Mentai Canape','GOLD',60000],
            ['18033','Salmon Crispy Aburi','GOLD',60000],
            ['18041','Tuna Salad Crispy','GOLD',60000],
            ['10006','Chuka lidako Chinmi','CHOCO M',64000],
            ['16001','Sanshoku Hana Salmon','CHOCO M',64000],
            ['16052','Salmon Hana Tobikko','CHOCO M',64000],
            ['14014','Hamachi Sashimi','CHOCO M',64000],
            ['111010','Mini TSCM','CHOCO M',64000],
            ['111005','Mini BSCM','CHOCO M',64000],
        ];

        $plateMap = PlateColors::pluck('id', 'platename')->toArray();

        foreach ($menus as [$code, $name, $plate, $price]) {
            Menu::updateOrCreate(
                ['code' => $code],  
                [
                    'id' => Str::uuid(),
                    'menuname' => $name,
                    'price' => $price,
                    'plate_color_id' => $plateMap[$plate] ?? null,
                    'description' => null,
                    'image' => null,
                    'shelf_life' => null,
                    'is_active' => true,
                ]
            );
        }
    }
}