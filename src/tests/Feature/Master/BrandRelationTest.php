<?php

namespace Tests\Feature\Master;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandRelationTest extends TestCase
{
    use RefreshDatabase;

    private function brand(string $code = 'MHR', string $name = 'Maharasa'): Brand
    {
        return Brand::create(['code' => $code, 'name' => $name, 'is_active' => true]);
    }

    public function test_brand_owns_outlets_menus_and_plate_colors(): void
    {
        $brand = $this->brand();

        Outlet::create([
            'code' => 'BDG', 'name' => 'Bandung', 'brand' => 'Maharasa',
            'brand_id' => $brand->id, 'is_active' => true,
        ]);

        $plate = PlateColors::create([
            'platename' => 'Merah', 'brand_id' => $brand->id, 'price' => 15000,
        ]);

        Menu::create([
            'menuname' => 'Salmon', 'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $plate->id, 'brand_id' => $brand->id,
        ]);

        $this->assertCount(1, $brand->outlets);
        $this->assertCount(1, $brand->menus);
        $this->assertCount(1, $brand->plateColors);
    }

    public function test_menu_and_plate_color_point_back_to_their_brand(): void
    {
        $brand = $this->brand();

        $plate = PlateColors::create([
            'platename' => 'Merah', 'brand_id' => $brand->id, 'price' => 15000,
        ]);

        $menu = Menu::create([
            'menuname' => 'Salmon', 'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $plate->id, 'brand_id' => $brand->id,
        ]);

        $this->assertSame($brand->id, $menu->brand->id);
        $this->assertSame($brand->id, $plate->brand->id);
    }

    /**
     * Alasan relasi di Outlet dinamai brandMaster(), bukan brand(): kolom teks
     * `brand` masih ada. Relasi bernama sama akan menang di toArray() dan
     * mengubah field `brand` pada respons outlet dari string jadi objek.
     */
    public function test_eager_loading_the_outlet_brand_does_not_reshape_the_legacy_text_field(): void
    {
        $brand = $this->brand();

        Outlet::create([
            'code' => 'BDG', 'name' => 'Bandung', 'brand' => 'Maharasa',
            'brand_id' => $brand->id, 'is_active' => true,
        ]);

        $outlet = Outlet::with('brandMaster')->firstOrFail();

        $this->assertSame('Maharasa', $outlet->brand);
        $this->assertSame('Maharasa', $outlet->toArray()['brand']);
        $this->assertSame($brand->id, $outlet->brandMaster->id);
    }

    public function test_two_brands_may_each_have_a_plate_color_with_the_same_name(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        PlateColors::create(['platename' => 'Merah', 'brand_id' => $maharasa->id, 'price' => 15000]);
        PlateColors::create(['platename' => 'Merah', 'brand_id' => $katsuri->id, 'price' => 21000]);

        // Inti dari keputusan "plate color per-brand": nama sama, harga beda.
        $this->assertSame(2, PlateColors::where('platename', 'Merah')->count());
    }

    public function test_one_brand_cannot_have_the_same_plate_color_twice(): void
    {
        $brand = $this->brand();

        PlateColors::create(['platename' => 'Merah', 'brand_id' => $brand->id, 'price' => 15000]);

        $this->expectException(QueryException::class);

        PlateColors::create(['platename' => 'Merah', 'brand_id' => $brand->id, 'price' => 21000]);
    }

    public function test_a_deleted_plate_color_name_can_be_used_again(): void
    {
        $brand = $this->brand();

        $plate = PlateColors::create(['platename' => 'Merah', 'brand_id' => $brand->id, 'price' => 15000]);
        $plate->delete();

        // Index-nya partial (WHERE deleted_at IS NULL) supaya alur hapus-lalu-
        // buat-lagi tetap sah.
        $again = PlateColors::create(['platename' => 'Merah', 'brand_id' => $brand->id, 'price' => 21000]);

        $this->assertNotSame($plate->id, $again->id);
        $this->assertSoftDeleted('plate_colors', ['id' => $plate->id]);
    }

    public function test_plate_colors_without_a_brand_are_not_constrained(): void
    {
        // Data yang belum di-backfill masih boleh punya nama duplikat — kalau
        // tidak, migration Fase 2 tidak akan pernah bisa jalan di basis data
        // yang brand-nya lebih dari satu.
        PlateColors::create(['platename' => 'Merah', 'brand_id' => null, 'price' => 15000]);
        PlateColors::create(['platename' => 'Merah', 'brand_id' => null, 'price' => 21000]);

        $this->assertSame(2, PlateColors::whereNull('brand_id')->count());
    }
}
