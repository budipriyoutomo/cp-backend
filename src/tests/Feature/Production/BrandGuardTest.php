<?php

namespace Tests\Feature\Production;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Fase 4 — jalur produksi menolak brand yang bukan miliknya.
 *
 * Piring menyimpan `plate_color` milik menunya, dan warna adalah unit harga.
 * Memproduksi menu brand lain di sebuah outlet berarti memasukkan harga brand
 * lain ke rekonsiliasi hari itu, dan itu baru ketahuan saat closing report
 * tidak balance — jauh dari penyebabnya.
 */
class BrandGuardTest extends TestCase
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

    private function menu(string $name, ?Brand $brand, PlateColors $color): Menu
    {
        return Menu::create([
            'menuname' => $name, 'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $brand?->id, 'is_active' => true,
        ]);
    }

    // ==================================================================
    // PRODUCE
    // ==================================================================

    public function test_producing_a_menu_from_another_brand_is_rejected(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $bandung = $this->outlet('BDG', $maharasa);
        $asing   = $this->menu('Unagi', $katsuri, $this->plateColor('Emas', $katsuri));

        $this->postJson('/api/production/produce', [
            'menuId'   => $asing->id,
            'quantity' => 2,
            'outletId' => $bandung->id,
        ])->assertStatus(422)->assertJsonPath('status', false);

        $this->assertSame(0, ProductionItem::count());
    }

    public function test_producing_a_menu_from_the_outlets_own_brand_still_works(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $bandung  = $this->outlet('BDG', $maharasa);
        $menu     = $this->menu('Salmon', $maharasa, $this->plateColor('Merah', $maharasa));

        $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 3,
            'outletId' => $bandung->id,
        ])->assertOk();

        $this->assertSame(3, ProductionItem::count());
    }

    public function test_a_menu_without_a_brand_can_still_be_produced(): void
    {
        // Kelonggaran transisi: tidak ada yang bisa dibandingkan, jadi tidak
        // ada yang bisa dilanggar. Menolaknya akan menghentikan dapur di basis
        // data yang belum di-backfill.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $bandung  = $this->outlet('BDG', $maharasa);
        $menu     = $this->menu('Warisan', null, $this->plateColor('Biru', null));

        $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 1,
            'outletId' => $bandung->id,
        ])->assertOk();

        $this->assertSame(1, ProductionItem::count());
    }

    // ==================================================================
    // PLAN
    // ==================================================================

    public function test_a_plan_naming_another_brands_colour_is_rejected(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $bandung = $this->outlet('BDG', $maharasa);
        $asing   = $this->plateColor('Emas', $katsuri);

        $this->postJson('/api/production/plan', [
            'outletId' => $bandung->id,
            'date'     => '2026-08-14',
            'plan'     => [[
                'timeSlot' => '08:00-09:00',
                'items'    => [['plateColorId' => $asing->id, 'qty' => 5]],
            ]],
        ])->assertStatus(422)->assertJsonPath('status', false);

        // Dicek sebelum transaksi dibuka — tidak ada plan separuh jadi.
        $this->assertDatabaseCount('production_plans', 0);
        $this->assertDatabaseCount('production_plan_items', 0);
    }

    public function test_a_plan_using_the_outlets_own_colour_still_saves(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $bandung  = $this->outlet('BDG', $maharasa);
        $merah    = $this->plateColor('Merah', $maharasa);

        $this->postJson('/api/production/plan', [
            'outletId' => $bandung->id,
            'date'     => '2026-08-14',
            'plan'     => [[
                'timeSlot' => '08:00-09:00',
                'items'    => [['plateColorId' => $merah->id, 'qty' => 5]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('production_plan_items', ['plate_color' => $merah->id, 'qty' => 5]);
    }

    // ==================================================================
    // DASHBOARD
    // ==================================================================

    public function test_dashboard_stats_only_list_the_outlets_own_colours(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $bandung = $this->outlet('BDG', $maharasa);

        $this->plateColor('Merah', $maharasa);
        $this->plateColor('Emas', $katsuri);

        $response = $this->getJson('/api/production/stats?outletId=' . $bandung->id)->assertOk();

        // Baris brand lain selalu nol, tapi tetap memenuhi layar tablet dan
        // bikin operator ragu.
        $this->assertEqualsCanonicalizing(
            ['Merah'],
            collect($response->json('data'))->pluck('plateColor')->all()
        );
    }
}
