<?php

namespace Tests\Feature\Master;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migrasi data brand adalah langkah yang paling sulit dibalik di seluruh
 * rencana ini, jadi ia diuji seperti kode biasa.
 *
 * RefreshDatabase sudah menjalankan seluruh migration terhadap basis data
 * kosong — tidak ada apa pun untuk di-backfill saat itu. Di sini file
 * migration-nya dimuat ulang dan `up()` dipanggil lagi terhadap data yang
 * memang sudah disiapkan. Aman karena langkahnya idempoten: hanya baris dengan
 * brand_id NULL yang disentuh.
 */
class BrandBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_14_000200_backfill_brand_ids.php');
        $migration->up();
    }

    private function insertOutlet(string $code, ?string $brandText): void
    {
        DB::table('outlets')->insert([
            'id'         => (string) Str::uuid(),
            'code'       => $code,
            'name'       => $code,
            'brand'      => $brandText,
            'brand_id'   => null,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPlateColor(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('plate_colors')->insert([
            'id'         => $id,
            'platename'  => $name,
            'brand_id'   => null,
            'price'      => 15000,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertMenu(string $name, string $plateColorId): void
    {
        DB::table('menus')->insert([
            'id'             => (string) Str::uuid(),
            'menuname'       => $name,
            'price'          => 20000,
            'shelf_life'     => 60,
            'plate_color_id' => $plateColorId,
            'brand_id'       => null,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_it_creates_brands_from_the_legacy_outlet_text(): void
    {
        $this->insertOutlet('BDG', 'Maharasa');
        $this->insertOutlet('JKT', 'Maharasa');

        $this->runBackfill();

        $this->assertSame(1, DB::table('brands')->count());
        $this->assertDatabaseHas('brands', ['name' => 'Maharasa', 'code' => 'MAHARASA']);
    }

    public function test_it_links_every_outlet_to_its_brand(): void
    {
        $this->insertOutlet('BDG', 'Maharasa');
        $this->insertOutlet('SBY', 'Katsuri');

        $this->runBackfill();

        $maharasa = DB::table('brands')->where('name', 'Maharasa')->value('id');
        $katsuri  = DB::table('brands')->where('name', 'Katsuri')->value('id');

        $this->assertSame($maharasa, DB::table('outlets')->where('code', 'BDG')->value('brand_id'));
        $this->assertSame($katsuri, DB::table('outlets')->where('code', 'SBY')->value('brand_id'));
    }

    public function test_with_a_single_brand_it_adopts_all_menus_and_plate_colors(): void
    {
        $this->insertOutlet('BDG', 'Maharasa');
        $plate = $this->insertPlateColor('Merah');
        $this->insertMenu('Salmon', $plate);

        $this->runBackfill();

        $brandId = DB::table('brands')->value('id');

        $this->assertSame($brandId, DB::table('plate_colors')->value('brand_id'));
        $this->assertSame($brandId, DB::table('menus')->value('brand_id'));
    }

    public function test_with_two_brands_it_refuses_to_guess_and_leaves_them_null(): void
    {
        $this->insertOutlet('BDG', 'Maharasa');
        $this->insertOutlet('SBY', 'Katsuri');
        $plate = $this->insertPlateColor('Merah');
        $this->insertMenu('Salmon', $plate);

        $this->runBackfill();

        // Menempelkan menu ke brand yang salah berarti menempelkan harga yang
        // salah. Lebih baik kosong dan berisik.
        $this->assertNull(DB::table('plate_colors')->value('brand_id'));
        $this->assertNull(DB::table('menus')->value('brand_id'));

        // Outlet tetap tersambung — itu tidak ambigu.
        $this->assertSame(2, DB::table('outlets')->whereNotNull('brand_id')->count());
    }

    public function test_it_reuses_a_brand_that_already_exists_regardless_of_case(): void
    {
        DB::table('brands')->insert([
            'id'         => $existing = (string) Str::uuid(),
            'code'       => 'MHR',
            'name'       => 'maharasa',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertOutlet('BDG', 'Maharasa');

        $this->runBackfill();

        $this->assertSame(1, DB::table('brands')->count());
        $this->assertSame($existing, DB::table('outlets')->where('code', 'BDG')->value('brand_id'));
    }

    public function test_an_outlet_without_brand_text_is_left_alone(): void
    {
        $this->insertOutlet('BDG', 'Maharasa');
        $this->insertOutlet('XXX', null);

        $this->runBackfill();

        $this->assertNull(DB::table('outlets')->where('code', 'XXX')->value('brand_id'));
        $this->assertNotNull(DB::table('outlets')->where('code', 'BDG')->value('brand_id'));
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->insertOutlet('BDG', 'Maharasa');
        $plate = $this->insertPlateColor('Merah');
        $this->insertMenu('Salmon', $plate);

        $this->runBackfill();

        $brands  = DB::table('brands')->count();
        $brandId = DB::table('brands')->value('id');

        $this->runBackfill();

        $this->assertSame($brands, DB::table('brands')->count());
        $this->assertSame($brandId, DB::table('brands')->value('id'));
        $this->assertSame($brandId, DB::table('menus')->value('brand_id'));
    }

    public function test_it_gives_two_brands_distinct_codes_even_when_the_names_collapse(): void
    {
        $this->insertOutlet('BDG', 'Maha Rasa');
        $this->insertOutlet('SBY', 'Maharasa');

        $this->runBackfill();

        $codes = DB::table('brands')->pluck('code');

        $this->assertSame(2, $codes->count());
        $this->assertSame($codes->count(), $codes->unique()->count());
    }
}
