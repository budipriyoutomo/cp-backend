<?php

namespace Tests\Feature\Master;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\WasteReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * `BaseResource::formatValue()` menebak tipe dari ISI nilai, bukan dari kolomnya.
 *
 * Untuk kolom varchar yang kebetulan berisi digit, tebakan itu selalu salah:
 * `menus.code = "12345"` dikirim sebagai number, dan layar /admin/menus mati
 * saat memanggil `.toLowerCase()` di atasnya. Yang lebih buruk, `"007"` lolos
 * `ctype_digit()` lalu jadi `7` — nilai yang berbeda, bukan sekadar tipe yang
 * salah.
 *
 * Resource yang punya kolom teks seperti itu mendaftarkannya di `$textFields`.
 */
class NumericTextFieldTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    private function plateColor(array $overrides = []): PlateColors
    {
        return PlateColors::create(array_merge([
            'platename'       => 'Merah',
            'price'           => 15000,
            'description'     => 'Warna merah',
            'target_foodcost' => 30,
            'is_active'       => true,
        ], $overrides));
    }

    private function menu(array $overrides = []): Menu
    {
        return Menu::create(array_merge([
            'code'           => 'SALMON-01',
            'menuname'       => 'Salmon',
            'description'    => 'Sushi salmon',
            'price'          => 20000,
            'shelf_life'     => 60,
            'plate_color_id' => $this->plateColor()->id,
            'is_active'      => true,
        ], $overrides));
    }

    public function test_all_digit_menu_code_stays_a_string(): void
    {
        $this->actingAsRole('admin');
        $this->menu(['code' => '12345']);

        $response = $this->getJson('/api/master/menu')->assertOk();

        $code = $response->json('data.0.code');

        $this->assertIsString($code, 'Kode menu harus tetap string, bukan number.');
        $this->assertSame('12345', $code);
    }

    /**
     * Kasus paling merusak: bukan tipe yang salah, tapi nilai yang hilang.
     */
    public function test_leading_zeros_in_a_menu_code_survive(): void
    {
        $this->actingAsRole('admin');
        $this->menu(['code' => '007']);

        $this->getJson('/api/master/menu')
            ->assertOk()
            ->assertJsonPath('data.0.code', '007');
    }

    public function test_all_digit_menu_name_stays_a_string(): void
    {
        $this->actingAsRole('admin');
        $this->menu(['menuname' => '2024']);

        $this->getJson('/api/master/menu')
            ->assertOk()
            ->assertJsonPath('data.0.menuname', '2024');
    }

    public function test_all_digit_plate_color_name_stays_a_string(): void
    {
        $this->actingAsRole('admin');
        $this->plateColor(['platename' => '88']);

        $response = $this->getJson('/api/master/platecolor')->assertOk();

        $this->assertIsString($response->json('data.0.platename'));
        $this->assertSame('88', $response->json('data.0.platename'));
    }

    /**
     * Pengecualiannya harus tetap sempit: `price` memang wajib angka, dan
     * pemakainya memanggil `toLocaleString()` di atasnya.
     */
    public function test_price_is_still_a_number(): void
    {
        $this->actingAsRole('admin');
        $this->plateColor(['price' => 15000]);

        $price = $this->getJson('/api/master/platecolor')->assertOk()->json('data.0.price');

        $this->assertIsNotString($price, 'Harga harus tetap angka, bukan string.');
        $this->assertEqualsWithDelta(15000, $price, 0.001);
    }

    /**
     * Yang paling berbahaya dari semuanya.
     *
     * `POSService::storeFromEvent()` mencocokkan payload POS ke `outlets.code`.
     * Kalau "01" dikirim sebagai `1`, pencocokan di sisi mana pun yang memakai
     * respons API akan meleset — dan yang salah adalah angka penjualan.
     */
    public function test_outlet_code_keeps_its_leading_zero(): void
    {
        $this->actingAsRole('admin');
        Outlet::create([
            'code'      => '01',
            'name'      => 'Bandung',
            'address'   => 'Jl. Merdeka',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/master/outlet')->assertOk();

        $this->assertIsString($response->json('data.0.code'));
        $this->assertSame('01', $response->json('data.0.code'));
    }

    public function test_all_digit_outlet_name_stays_a_string(): void
    {
        $this->actingAsRole('admin');
        Outlet::create([
            'code'      => 'BDG',
            'name'      => '99',
            'is_active' => true,
        ]);

        $this->getJson('/api/master/outlet')
            ->assertOk()
            ->assertJsonPath('data.0.name', '99');
    }

    public function test_brand_code_keeps_its_leading_zero(): void
    {
        $this->actingAsRole('admin');
        Brand::create([
            'code'      => '007',
            'name'      => 'Maharasa',
            'is_active' => true,
        ]);

        $this->getJson('/api/master/brand')
            ->assertOk()
            ->assertJsonPath('data.0.code', '007');
    }

    public function test_all_digit_waste_reason_stays_a_string(): void
    {
        $this->actingAsRole('admin');
        WasteReason::create([
            'reason_name' => '404',
            'description' => '123',
            'is_active'   => true,
        ]);

        $this->getJson('/api/master/waste-reason')
            ->assertOk()
            ->assertJsonPath('data.0.reason_name', '404')
            ->assertJsonPath('data.0.description', '123');
    }

    /**
     * `is_active` melewati cabang `is_bool()` sebelum tebakan angka, dan harus
     * tetap begitu — pengecualian teks tidak boleh menyeretnya.
     */
    public function test_booleans_are_untouched(): void
    {
        $this->actingAsRole('admin');
        $this->plateColor(['is_active' => true]);

        $this->assertTrue(
            $this->getJson('/api/master/platecolor')->assertOk()->json('data.0.is_active')
        );
    }
}
