<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\PlateColors;
use Illuminate\Database\Seeder;

/**
 * Master menu — 72 item, disamakan dengan dump `menus` produksi.
 *
 * Baris diurutkan per plate color (harga naik), lalu per `code`. Harga di sini
 * memang menggandakan `plate_colors.price`: POS menjual per warna piring, tapi
 * `menus.price` yang dipakai laporan. Kalau harga sebuah warna berubah, ubah
 * PlateColorSeeder DAN baris-baris di sini.
 *
 * Aman diulang: dicocokkan lewat `code`.
 */
class MenuSeeder extends Seeder
{
    /**
     * Semua menu punya shelf life 120 menit di produksi. Nilai ini yang dipakai
     * `ProductionItemService::produce()` untuk menghitung `expires_at`; kalau
     * NULL, service jatuh ke default 60 menit — separuh umur yang sebenarnya.
     */
    private const SHELF_LIFE_MINUTES = 120;

    /**
     * [code, menuname, plate color, price, image]
     *
     * `image` adalah key objek di S3, bukan URL — `Menu::getImageUrlAttribute()`
     * yang menyusun URL-nya.
     */
    private const MENUS = [
        ['16022',       'Inari Sushi',                   'WHITE',    23000, 'menus/b43fde1b-27e4-4627-8d19-300dcacd47bf.jpg'],

        ['10002',       'Edamame',                       'BLUE',     30000, 'menus/6c8557ce-cea0-4f3d-9487-b96f7d5a7888.jpg'],
        ['10020',       'Tamagoyaki',                    'BLUE',     30000, null],
        ['16005',       'Tako Sushi',                    'BLUE',     30000, 'menus/69aab6e2-4ac0-46bc-a0be-7e8391bd5cc6.jpg'],
        ['16013',       'Ika Sushi',                     'BLUE',     30000, 'menus/9b3218a1-4eb7-4199-b5dc-62eaf6769736.jpg'],
        ['16019',       'Salmon Sushi',                  'BLUE',     30000, 'menus/a37ed076-4a42-4e87-b98a-f5d5fff0b7ec.jpg'],
        ['16040',       'Aburi Ika Sushi',               'BLUE',     30000, 'menus/87ab73ef-0cd3-4bf2-958c-991328506db2.jpg'],
        ['16048',       'Kani Mentai Sushi',             'BLUE',     30000, 'menus/44daa831-1e80-4274-bc7f-b8e1ce77fca9.jpg'],
        ['16056',       'Inari Kanimayo',                'BLUE',     30000, 'menus/59e1f2ab-75cd-4e3f-8b81-8dce31b4b0e9.jpg'],
        ['16057',       'Shima Aji Aburi Sushi',         'BLUE',     30000, 'menus/331bd7ab-b1b2-4548-b10e-0eb658852f8a.jpg'],
        ['16058',       'Shima Aji Sushi',               'BLUE',     30000, 'menus/727f6d45-ea40-4eac-8588-aaf06bbb7744.jpg'],
        ['16064',       'Tamago Mentai Sushi',           'BLUE',     30000, 'menus/ea83c1cd-da15-4268-bfc4-4f51ec1cd1c1.jpg'],
        ['18014',       'Kani Kama Maki',                'BLUE',     30000, 'menus/9bee61b1-aa4a-4276-a69f-ad23fc7c476b.png'],
        ['18019',       'Tuna Salad Maki',               'BLUE',     30000, 'menus/8926f407-f84b-406c-a3d6-5becbc38531d.png'],
        ['18022',       'Kani Tamago Maki',              'BLUE',     30000, 'menus/aa668e86-0f93-4135-aae7-c46f0b361c80.png'],

        ['16009',       'Maguro Sushi',                  'YELLOW',   32000, 'menus/70c3eb0a-42ac-4d37-b30d-155910957aff.jpg'],
        ['16049',       'Inari Tuna Salad',              'YELLOW',   32000, 'menus/fa5cf092-b8ba-4545-a7f8-c28086a11f45.jpg'],
        ['18008',       'Tekka Maki',                    'YELLOW',   32000, null],
        ['18059',       'Crispy Cheese Roll',            'YELLOW',   32000, null],

        ['0ST00070001', 'Aburi Hirame Mentai Sushi',     'PINK',     38000, 'menus/3878f7e5-5626-461b-8c3d-eae5b8a060d9.jpg'],
        ['10005',       'Kanimayo Chinmi',               'PINK',     38000, 'menus/7f892e67-80f6-4253-a010-01e5e21739ec.png'],
        ['10008',       'Chuka Chinmi',                  'PINK',     38000, 'menus/c696d197-f615-45e4-aefe-fe6d65d65452.png'],
        ['16007',       'Salmon Mentai Sushi',           'PINK',     38000, null],
        ['16012',       'Salmon Belly Sushi',            'PINK',     38000, 'menus/d48a1351-b3be-43a9-a546-1b14af5dd7ec.jpg'],
        ['16015',       'Ebi Mentai Sushi',              'PINK',     38000, 'menus/8ebfdff6-481d-49df-825d-a45bef72d4a0.jpg'],
        ['16026',       'Aburi Salmon Belly Sushi',      'PINK',     38000, 'menus/f1ea2592-998a-4f93-af52-1ebae6000b5e.jpg'],
        ['16065',       'Smoked Salmon Inari Sushi',     'PINK',     38000, 'menus/9fb351c7-e0d2-41b3-88bb-852fd76250f0.jpg'],
        ['16066',       'Spicy Salmon Inari Sushi',      'PINK',     38000, 'menus/8477e051-acca-4ba3-b0c1-72a2adad3340.jpg'],
        ['18006',       'Salmon Maki',                   'PINK',     38000, null],
        ['18009',       'Ebi Tempura Maki',              'PINK',     38000, null],
        ['18015',       'Kanimayo Tobiko Maki',          'PINK',     38000, null],
        ['32446',       'Kani Tempura Floss Roll',       'PINK',     38000, null],

        ['11017',       'Stamina Roll',                  'SILVER',   42000, 'menus/4afc810d-acc5-4fcc-a762-d34475706fa9.png'],
        ['11061',       'Lava Roll',                     'SILVER',   42000, null],
        ['16003',       'Hamachi Sushi',                 'SILVER',   42000, 'menus/3ba40461-fbc6-450f-aac0-d633b65dc98d.jpg'],
        ['16021',       'Nama Hotate Sushi',             'SILVER',   42000, 'menus/6a8cff52-5ae5-4ce1-87d1-1487eee1f111.jpg'],
        ['16034',       'Aburi Hamachi Sushi',           'SILVER',   42000, 'menus/5caef368-bcc6-42fe-a6ad-1345e1d9e039.jpg'],
        ['16038',       'Aburi Nama Hotate Sushi',       'SILVER',   42000, 'menus/38792760-4fe4-4e57-a2b6-8be6c00f780b.jpg'],
        ['16061',       'Inari Lobster Salad',           'SILVER',   42000, 'menus/4e0baec7-7867-44f1-9281-61f3578ec762.jpg'],
        ['18001',       'Ebiten Floss Maki',             'SILVER',   42000, 'menus/4040dac3-cd8c-4849-b066-b4a651868ae1.png'],
        ['18020',       'Mini California Maki',          'SILVER',   42000, 'menus/b4d3e822-62f7-4fea-ba52-bddbf8c9989b.png'],
        ['18056',       'Kani Mentai Mayo Roll',         'SILVER',   42000, 'menus/8d0bcad1-d269-4d1a-9f64-2ea2010bf553.png'],
        ['32445',       'Spicy Tuna Salad Tobikko Roll', 'SILVER',   42000, null],
        ['32447',       'Special Salmon Cheese Sushi',   'SILVER',   42000, null],

        ['0ST00070002', 'Jo Unagi SH (1 pcs)',           'BLACK',    47000, null],
        ['0ST00300014', 'Salmon Kama Karaage',           'BLACK',    47000, null],
        ['10011',       'Chuka Kurage Chinmi',           'BLACK',    47000, 'menus/021ffbce-be19-4a17-977d-87b2c1492ffd.png'],
        ['10014',       'Chuka Wakame Chinmi',           'BLACK',    47000, 'menus/4921c01c-814f-4002-949a-f1acec485056.png'],
        ['10027',       'Lobster Salad Chinmi',          'BLACK',    47000, 'menus/1413ae88-45d0-4dd8-aa94-36324118677d.png'],
        ['16062',       'Aburi Nama Hotate Mentai',      'BLACK',    47000, 'menus/ba53082c-91af-40f7-ba05-9bd548f343a3.jpg'],
        ['18004',       'Spicy Salmon Maki',             'BLACK',    47000, null],
        ['18025',       'Tempura Maki Ebikko',           'BLACK',    47000, 'menus/e21af9bb-af00-4036-902e-dde5da2f739c.png'],
        ['18057',       'Karaage Roll',                  'BLACK',    47000, 'menus/963566c4-3dcf-4a9f-b18c-5b5e30001b4f.png'],
        ['20041',       'Salmon Kama Mentai',            'BLACK',    47000, null],
        ['20048',       'Spicy Haruyaki',                'BLACK',    47000, 'menus/8e85d08a-1ccd-4ae5-b3f9-7f43a84e9f9e.png'],
        ['32608',       'Volcano Roll',                  'BLACK',    47000, null],

        ['11013',       'Crispy Roll',                   'RED',      53000, null],
        ['11037',       'Fuji Roll',                     'RED',      53000, null],
        ['14003',       'Maguro Sashimi',                'RED',      53000, null],
        ['18013',       'Baked Salmon Maki',             'RED',      53000, 'menus/cd832589-0cea-403b-a0fe-eb8cae34bdf0.png'],
        ['18017',       'Salmon Skin Maki',              'RED',      53000, null],
        ['18046',       'Spicy Crispy Roll',             'RED',      53000, 'menus/fc59baf5-8830-4856-abb8-27a8705992d8.png'],

        ['18002',       'Soft Shel Crab Maki',           'GOLD',     60000, null],
        ['18033',       'Salmon Crispy Aburi',           'GOLD',     60000, 'menus/07a115c0-f842-4abc-a352-d87a32285b1e.png'],
        ['18041',       'Tuna Salad Crispy',             'GOLD',     60000, 'menus/4655c5ab-b95c-4a86-ad08-c558b1124bc3.png'],
        ['18050',       'Salmon Mentai Canape',          'GOLD',     60000, null],

        ['10006',       'Chuka lidako Chinmi',           'CHOCO M',  64000, 'menus/4fcf4f1c-45ed-4d4a-b375-6be4e98f53e8.png'],
        ['111005',      'Mini BSCM',                     'CHOCO M',  64000, null],
        ['111010',      'Mini TSCM',                     'CHOCO M',  64000, null],
        ['14014',       'Hamachi Sashimi',               'CHOCO M',  64000, 'menus/f3b90079-35fa-4b77-a271-33f8fa72620d.png'],
        ['16001',       'Sanshoku Hana Salmon',          'CHOCO M',  64000, 'menus/c471951a-965e-47c9-be5b-1f0788d874b0.jpg'],
        ['16052',       'Salmon Hana Tobikko',           'CHOCO M',  64000, null],
    ];

    public function run(): void
    {
        $brandId = BrandSeeder::soleBrandId();
        BrandSeeder::warnIfAmbiguous($this, 'Menu');

        // Warna dicari di dalam brand yang sama. Dua brand boleh sama-sama punya
        // "WHITE" dengan harga berbeda, jadi peta global akan menempelkan menu
        // ke piring brand lain — tanpa error, hanya harga yang salah.
        $plateMap = PlateColors::query()
            ->where('brand_id', $brandId)
            ->pluck('id', 'platename')
            ->all();

        $skipped = 0;

        foreach (self::MENUS as [$code, $name, $plate, $price, $image]) {
            // `menus.plate_color_id` NOT NULL — dulu baris ini mengirim null dan
            // seeder mati dengan error constraint yang tidak menjelaskan apa pun.
            if (!isset($plateMap[$plate])) {
                $this->command?->warn("Lewati {$code} ({$name}) — plate color {$plate} belum ada. Jalankan PlateColorSeeder dulu.");
                $skipped++;

                continue;
            }

            // `brand_id` ikut jadi kunci pencocokan: kode menu unik di dalam
            // brand, bukan global.
            $menu = Menu::firstOrNew(['code' => $code, 'brand_id' => $brandId]);

            $menu->fill([
                'menuname'       => $name,
                'price'          => $price,
                'plate_color_id' => $plateMap[$plate],
                'brand_id'       => $brandId,
                'shelf_life'     => self::SHELF_LIFE_MINUTES,
                'is_active'      => true,
            ]);

            // Hanya diisi saat baris baru. Versi lama seeder menulis
            // `'image' => null` pada setiap run, jadi foto yang diunggah lewat
            // admin hilang tiap kali seeder dijalankan ulang.
            if (!$menu->exists) {
                $menu->description = $name;
                $menu->image       = $image;
            }

            $menu->save();
        }

        $total = count(self::MENUS) - $skipped;
        $this->command?->info("Menu siap: {$total} item" . ($skipped > 0 ? ", {$skipped} dilewati." : '.'));
    }
}
