<?php

namespace Tests\Feature\Master;

use App\Models\Brand;
use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * `brand_id` outlet adalah pangkal seluruh penyaringan brand: menu dan plate
 * color diresolusi lewat outlet, jadi outlet yang brand_id-nya NULL tidak
 * menyaring apa pun dan melihat master semua brand.
 *
 * OutletRequest dulu tidak punya aturan untuk `brand_id`, jadi `validated()`
 * membuangnya diam-diam. Form mengirimnya, respons balik terlihat sukses, dan
 * kolomnya tetap NULL — tanpa satu pun pesan error.
 */
class OutletBrandTest extends TestCase
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

    public function test_creating_an_outlet_stores_its_brand_id(): void
    {
        $tomSushi = $this->brand('TS', 'Tom Sushi');

        $this->postJson('/api/master/outlet', [
            'code'     => 'TOM1',
            'name'     => 'Tom Sushi Bandung',
            'brand'    => 'Tom Sushi',
            'brand_id' => $tomSushi->id,
            'address'  => 'Jl. Merdeka 1',
        ])->assertStatus(201);

        $this->assertDatabaseHas('outlets', [
            'code'     => 'TOM1',
            'brand_id' => $tomSushi->id,
        ]);
    }

    public function test_updating_an_outlet_stores_its_brand_id(): void
    {
        $sushiTei = $this->brand('ST', 'Sushi Tei');
        $tomSushi = $this->brand('TS', 'Tom Sushi');

        $outlet = Outlet::create([
            'code' => 'TOM1', 'name' => 'Tom Sushi Bandung',
            'brand' => 'Sushi Tei', 'brand_id' => $sushiTei->id, 'is_active' => true,
        ]);

        $this->putJson('/api/master/outlet/' . $outlet->id, [
            'code'     => 'TOM1',
            'name'     => 'Tom Sushi Bandung',
            'brand'    => 'Tom Sushi',
            'brand_id' => $tomSushi->id,
            'address'  => 'Jl. Merdeka 1',
        ])->assertOk();

        $this->assertSame($tomSushi->id, $outlet->refresh()->brand_id);
    }

    public function test_the_saved_brand_id_comes_back_in_the_response(): void
    {
        $tomSushi = $this->brand('TS', 'Tom Sushi');

        // Form mengisi ulang dari `outlet.brandId`. Kalau resource tidak
        // membawanya, BrandSelect terlihat kosong walaupun datanya benar.
        $this->postJson('/api/master/outlet', [
            'code'     => 'TOM1',
            'name'     => 'Tom Sushi Bandung',
            'brand'    => 'Tom Sushi',
            'brand_id' => $tomSushi->id,
            'address'  => 'Jl. Merdeka 1',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.brand_id', $tomSushi->id);
    }

    public function test_an_unknown_brand_is_rejected_rather_than_stored(): void
    {
        $this->brand('TS', 'Tom Sushi');

        $this->postJson('/api/master/outlet', [
            'code'     => 'TOM1',
            'name'     => 'Tom Sushi Bandung',
            'brand'    => 'Tom Sushi',
            'brand_id' => fake()->uuid(),
            'address'  => 'Jl. Merdeka 1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand_id']);
    }

    public function test_with_brands_present_an_outlet_must_say_which_one(): void
    {
        $this->brand('ST', 'Sushi Tei');
        $this->brand('TS', 'Tom Sushi');

        // Dua brand: menebak berarti outlet melihat master brand yang salah.
        $this->postJson('/api/master/outlet', [
            'code'    => 'TOM1',
            'name'    => 'Tom Sushi Bandung',
            'brand'   => 'Tom Sushi',
            'address' => 'Jl. Merdeka 1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand_id']);
    }

    public function test_with_one_brand_the_outlet_adopts_it_without_being_told(): void
    {
        $only = $this->brand('MHR', 'Maharasa');

        // Tablet dengan bundel PWA lama masih mengirim payload tanpa brand_id.
        $this->postJson('/api/master/outlet', [
            'code'    => 'BDG',
            'name'    => 'Bandung',
            'brand'   => 'Maharasa',
            'address' => 'Jl. Merdeka 1',
        ])->assertStatus(201);

        $this->assertDatabaseHas('outlets', ['code' => 'BDG', 'brand_id' => $only->id]);
    }

    public function test_without_any_brand_yet_an_outlet_can_still_be_created(): void
    {
        // Basis data baru sah belum punya brand sama sekali; master tetap harus
        // bisa diisi, sama seperti menu dan plate color.
        $this->postJson('/api/master/outlet', [
            'code'    => 'BDG',
            'name'    => 'Bandung',
            'address' => 'Jl. Merdeka 1',
        ])->assertStatus(201);

        $this->assertDatabaseHas('outlets', ['code' => 'BDG', 'brand_id' => null]);
    }
}
