<?php

namespace Tests\Feature\Master;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use Tests\Concerns\CreatesUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4 dari docs/brand-feature-plan.md — master data disaring per brand.
 *
 * Klien mengirim `?outlet_id=`, bukan `?brand_id=`. Pemetaan outlet → brand
 * adalah aturan bisnis dan hidup di service; frontend tidak perlu tahu bahwa
 * brand adalah perantaranya.
 */
class BrandScopedMasterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    private function brand(string $code, string $name): Brand
    {
        return Brand::create(['code' => $code, 'name' => $name, 'is_active' => true]);
    }

    private function outlet(string $code, ?Brand $brand): Outlet
    {
        return Outlet::create([
            'code' => $code, 'name' => $code, 'brand' => $brand?->name,
            'brand_id' => $brand?->id, 'is_active' => true,
        ]);
    }

    private function plateColor(string $name, ?Brand $brand): PlateColors
    {
        return PlateColors::create([
            'platename' => $name, 'brand_id' => $brand?->id,
            'price' => 15000, 'is_active' => true,
        ]);
    }

    private function menu(string $name, ?Brand $brand, PlateColors $color, array $overrides = []): Menu
    {
        return Menu::create(array_merge([
            'menuname'       => $name,
            'price'          => 20000,
            'shelf_life'     => 60,
            'plate_color_id' => $color->id,
            'brand_id'       => $brand?->id,
            'is_active'      => true,
        ], $overrides));
    }

    // ==================================================================
    // READ: penyaringan lewat outlet_id
    // ==================================================================

    public function test_menu_index_returns_only_the_brand_of_the_given_outlet(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');
        $bandung  = $this->outlet('BDG', $maharasa);

        $this->menu('Salmon', $maharasa, $this->plateColor('Merah', $maharasa));
        $this->menu('Unagi', $katsuri, $this->plateColor('Merah', $katsuri));

        $response = $this->getJson('/api/master/menu?outlet_id=' . $bandung->id)->assertOk();

        $names = collect($response->json('data'))->pluck('menuname');

        $this->assertEqualsCanonicalizing(['Salmon'], $names->all());
    }

    public function test_menu_index_still_includes_menus_that_have_no_brand_yet(): void
    {
        // Kelonggaran transisi yang sama dengan POSService: NULL berarti "belum
        // ditetapkan", bukan "milik orang lain". Tanpa ini dapur melihat layar
        // kosong di basis data yang belum di-backfill.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $bandung  = $this->outlet('BDG', $maharasa);

        $this->menu('Salmon', $maharasa, $this->plateColor('Merah', $maharasa));
        $this->menu('Warisan', null, $this->plateColor('Biru', null));

        $response = $this->getJson('/api/master/menu?outlet_id=' . $bandung->id)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Salmon', 'Warisan'],
            collect($response->json('data'))->pluck('menuname')->all()
        );
    }

    public function test_menu_index_keeps_hiding_inactive_menus(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $bandung  = $this->outlet('BDG', $maharasa);
        $color    = $this->plateColor('Merah', $maharasa);

        $this->menu('Salmon', $maharasa, $color);
        $this->menu('Pensiun', $maharasa, $color, ['is_active' => false]);

        $response = $this->getJson('/api/master/menu?outlet_id=' . $bandung->id)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Salmon'],
            collect($response->json('data'))->pluck('menuname')->all()
        );
    }

    public function test_plate_color_index_returns_only_the_brand_of_the_given_outlet(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');
        $bandung  = $this->outlet('BDG', $maharasa);

        $this->plateColor('Merah', $maharasa);
        $this->plateColor('Merah', $katsuri);
        $this->plateColor('Emas', $katsuri);

        $response = $this->getJson('/api/master/platecolor?outlet_id=' . $bandung->id . '&per_page=all')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Merah', $response->json('data.0.platename'));
    }

    public function test_an_outlet_without_a_brand_sees_everything(): void
    {
        // Menyaringnya jadi "brand_id IS NULL" akan menyembunyikan seluruh
        // master dari outlet yang brand-nya belum diisi — gagal diam-diam.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $bandung  = $this->outlet('BDG', null);

        $this->plateColor('Merah', $maharasa);
        $this->plateColor('Biru', null);

        $response = $this->getJson('/api/master/platecolor?outlet_id=' . $bandung->id . '&per_page=all')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_an_unknown_outlet_is_rejected_rather_than_silently_unfiltered(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $this->plateColor('Merah', $maharasa);

        $this->getJson('/api/master/platecolor?outlet_id=' . \Illuminate\Support\Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_a_malformed_outlet_id_is_a_404_not_a_crash(): void
    {
        // `outlets.id` bertipe uuid di PostgreSQL; membandingkannya dengan
        // string sembarang melempar error SQL, bukan "tidak ketemu". Nilainya
        // datang dari query string, jadi apa pun bisa masuk.
        $this->getJson('/api/master/platecolor?outlet_id=bukan-uuid')
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_without_outlet_id_nothing_is_filtered(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $this->plateColor('Merah', $maharasa);
        $this->plateColor('Emas', $katsuri);

        $response = $this->getJson('/api/master/platecolor?per_page=all')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // ==================================================================
    // WRITE: brand_id yang diisi sendiri
    // ==================================================================

    public function test_with_one_brand_a_new_plate_color_adopts_it_without_being_told(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');

        $this->postJson('/api/master/platecolor', [
            'platename' => 'Hijau',
            'price'     => 15000,
        ])->assertCreated();

        $this->assertDatabaseHas('plate_colors', [
            'platename' => 'Hijau',
            'brand_id'  => $maharasa->id,
        ]);
    }

    public function test_with_two_brands_the_caller_must_say_which(): void
    {
        $this->brand('MHR', 'Maharasa');
        $this->brand('KTR', 'Katsuri');

        // Ditolak, bukan ditebak. Menebak berarti menempelkan harga brand A ke
        // piring brand B.
        $this->postJson('/api/master/platecolor', [
            'platename' => 'Hijau',
            'price'     => 15000,
        ])->assertStatus(422)->assertJsonValidationErrors(['brand_id']);
    }

    public function test_without_any_brand_yet_the_master_still_accepts_data(): void
    {
        // BrandSeeder melewati dirinya sendiri kalau BOOTSTRAP_BRANDS kosong,
        // jadi basis data baru sah-sah saja belum punya brand. `required` polos
        // akan mengunci master menu dan plate color sepenuhnya.
        $this->postJson('/api/master/platecolor', [
            'platename' => 'Hijau',
            'price'     => 15000,
        ])->assertCreated();

        $this->assertDatabaseHas('plate_colors', ['platename' => 'Hijau', 'brand_id' => null]);
    }

    public function test_an_explicit_brand_id_always_wins(): void
    {
        $this->brand('MHR', 'Maharasa');
        $katsuri = $this->brand('KTR', 'Katsuri');

        $this->postJson('/api/master/platecolor', [
            'platename' => 'Hijau',
            'price'     => 15000,
            'brand_id'  => $katsuri->id,
        ])->assertCreated();

        $this->assertDatabaseHas('plate_colors', [
            'platename' => 'Hijau',
            'brand_id'  => $katsuri->id,
        ]);
    }

    public function test_an_unknown_brand_id_is_rejected(): void
    {
        $this->postJson('/api/master/platecolor', [
            'platename' => 'Hijau',
            'price'     => 15000,
            'brand_id'  => (string) \Illuminate\Support\Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors(['brand_id']);
    }

    // ==================================================================
    // WRITE: keunikan platename sekarang per-brand
    // ==================================================================

    public function test_two_brands_may_each_create_a_plate_color_with_the_same_name(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $this->postJson('/api/master/platecolor', [
            'platename' => 'Merah', 'price' => 15000, 'brand_id' => $maharasa->id,
        ])->assertCreated();

        // Ini yang dulu ditolak 422 oleh aturan unik global — padahal justru
        // inti dari keputusan "plate color per-brand": nama sama, harga beda.
        $this->postJson('/api/master/platecolor', [
            'platename' => 'Merah', 'price' => 21000, 'brand_id' => $katsuri->id,
        ])->assertCreated();

        $this->assertSame(2, PlateColors::where('platename', 'Merah')->count());
    }

    public function test_one_brand_still_cannot_create_the_same_plate_color_twice(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');

        $this->postJson('/api/master/platecolor', [
            'platename' => 'Merah', 'price' => 15000, 'brand_id' => $maharasa->id,
        ])->assertCreated();

        // 422 yang bisa dibaca, bukan error SQL dari partial unique index.
        $this->postJson('/api/master/platecolor', [
            'platename' => 'Merah', 'price' => 21000, 'brand_id' => $maharasa->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['platename']);
    }

    public function test_two_brands_may_each_create_a_menu_with_the_same_name(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $merahMaharasa = $this->plateColor('Merah', $maharasa);
        $merahKatsuri  = $this->plateColor('Merah', $katsuri);

        $this->postJson('/api/master/menu', [
            'code' => 'S001', 'menuname' => 'Salmon Nigiri', 'description' => 'x',
            'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $merahMaharasa->id, 'brand_id' => $maharasa->id,
        ])->assertCreated();

        // Nama DAN kode sama, brand beda — boleh.
        $this->postJson('/api/master/menu', [
            'code' => 'S001', 'menuname' => 'Salmon Nigiri', 'description' => 'x',
            'price' => 26000, 'shelf_life' => 60,
            'plate_color_id' => $merahKatsuri->id, 'brand_id' => $katsuri->id,
        ])->assertCreated();

        $this->assertSame(2, Menu::where('menuname', 'Salmon Nigiri')->count());
    }

    public function test_one_brand_still_cannot_create_the_same_menu_name_twice(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $color    = $this->plateColor('Merah', $maharasa);

        $this->postJson('/api/master/menu', [
            'code' => 'S001', 'menuname' => 'Salmon Nigiri', 'description' => 'x',
            'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $maharasa->id,
        ])->assertCreated();

        $this->postJson('/api/master/menu', [
            'code' => 'S002', 'menuname' => 'Salmon Nigiri', 'description' => 'x',
            'price' => 22000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $maharasa->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['menuname']);
    }

    public function test_one_brand_still_cannot_reuse_a_menu_code(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $color    = $this->plateColor('Merah', $maharasa);

        $this->postJson('/api/master/menu', [
            'code' => 'S001', 'menuname' => 'Salmon Nigiri', 'description' => 'x',
            'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $maharasa->id,
        ])->assertCreated();

        $this->postJson('/api/master/menu', [
            'code' => 'S001', 'menuname' => 'Tuna Nigiri', 'description' => 'x',
            'price' => 22000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $maharasa->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_a_menu_can_still_be_updated_without_tripping_its_own_name(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $color    = $this->plateColor('Merah', $maharasa);
        $menu     = $this->menu('Salmon Nigiri', $maharasa, $color, ['code' => 'S001']);

        $this->putJson("/api/master/menu/{$menu->id}", [
            'code' => 'S001', 'menuname' => 'Salmon Nigiri', 'description' => 'x',
            'price' => 25000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $maharasa->id,
        ])->assertOk();

        $this->assertDatabaseHas('menus', ['id' => $menu->id, 'price' => 25000]);
    }

    public function test_a_brandless_row_blocks_the_same_name_in_any_brand(): void
    {
        // Baris tanpa brand terlihat SEMUA outlet, jadi membiarkan brand mana
        // pun membuat kembarannya berarti satu dapur melihat dua "Merah".
        // Jalan keluarnya bukan bikin baru, tapi menetapkan brand pada yang lama.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $this->brand('KTR', 'Katsuri');

        $this->plateColor('Merah', null);

        $this->postJson('/api/master/platecolor', [
            'platename' => 'Merah', 'price' => 15000, 'brand_id' => $maharasa->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['platename']);
    }

    public function test_a_new_brandless_row_may_not_shadow_an_existing_brand(): void
    {
        // Arah sebaliknya: baris baru tanpa brand akan muncul di outlet
        // Maharasa berdampingan dengan "Merah" miliknya sendiri.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $this->brand('KTR', 'Katsuri');

        $this->plateColor('Merah', $maharasa);

        $this->postJson('/api/master/platecolor', [
            'platename' => 'Merah', 'price' => 15000,
        ])->assertStatus(422)->assertJsonValidationErrors(['platename']);
    }

    public function test_the_database_refuses_a_duplicate_menu_name_within_a_brand(): void
    {
        // Validasi request bisa dilewati lewat seeder, tinker, atau migrasi.
        // Itu persis cara plate color dulu bisa menyimpang.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $color    = $this->plateColor('Merah', $maharasa);

        $this->menu('Salmon Nigiri', $maharasa, $color, ['code' => 'S001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->menu('Salmon Nigiri', $maharasa, $color, ['code' => 'S002']);
    }

    public function test_a_plate_color_can_still_be_renamed_to_its_own_name(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $color    = $this->plateColor('Merah', $maharasa);

        $this->putJson("/api/master/platecolor/{$color->id}", [
            'platename' => 'Merah',
            'price'     => 18000,
            'brand_id'  => $maharasa->id,
        ])->assertOk();

        $this->assertDatabaseHas('plate_colors', ['id' => $color->id, 'price' => 18000]);
    }
}
