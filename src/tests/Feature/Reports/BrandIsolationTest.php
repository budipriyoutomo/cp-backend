<?php

namespace Tests\Feature\Reports;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\POSData;
use App\Models\ProductionItem;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Fase 6 dari docs/brand-feature-plan.md.
 *
 * Seluruh jalur laporan mengelompokkan angka per `plate_color_id` dan menyaring
 * per `outlet_id`. Karena id warna unik per brand dan satu outlet melayani satu
 * brand, angkanya secara konstruksi tidak bisa tercampur.
 *
 * "Secara konstruksi" bukan bukti. Fase ini tidak mengubah kode laporan — ia
 * mengunci sifat itu dengan test, supaya perubahan berikutnya yang diam-diam
 * mengelompokkan per NAMA warna (yang kini bisa sama antar brand) langsung
 * ketahuan.
 *
 * Skenario yang dipakai di semua test: dua brand, dua outlet, dua warna
 * bernama sama persis "Merah" dengan harga berbeda.
 */
class BrandIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    private Brand $maharasa;
    private Brand $katsuri;
    private Outlet $bandung;
    private Outlet $surabaya;
    private PlateColors $merahMaharasa;
    private PlateColors $merahKatsuri;
    private Menu $menuMaharasa;
    private Menu $menuKatsuri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');

        $this->maharasa = Brand::create(['code' => 'MHR', 'name' => 'Maharasa', 'is_active' => true]);
        $this->katsuri  = Brand::create(['code' => 'KTR', 'name' => 'Katsuri', 'is_active' => true]);

        $this->bandung  = $this->outlet('BDG', $this->maharasa);
        $this->surabaya = $this->outlet('SBY', $this->katsuri);

        // Nama sama, harga beda — inti dari keputusan plate color per-brand.
        $this->merahMaharasa = $this->plateColor('Merah', $this->maharasa, 15000);
        $this->merahKatsuri  = $this->plateColor('Merah', $this->katsuri, 21000);

        $this->menuMaharasa = $this->menu('Salmon', $this->maharasa, $this->merahMaharasa);
        $this->menuKatsuri  = $this->menu('Salmon', $this->katsuri, $this->merahKatsuri);
    }

    private function outlet(string $code, Brand $brand): Outlet
    {
        return Outlet::create([
            'code' => $code, 'name' => $code, 'brand' => $brand->name,
            'brand_id' => $brand->id, 'is_active' => true,
        ]);
    }

    private function plateColor(string $name, Brand $brand, int $price): PlateColors
    {
        return PlateColors::create([
            'platename' => $name, 'brand_id' => $brand->id,
            'price' => $price, 'is_active' => true,
        ]);
    }

    private function menu(string $name, Brand $brand, PlateColors $color): Menu
    {
        return Menu::create([
            'menuname' => $name, 'price' => 20000, 'shelf_life' => 60,
            'plate_color_id' => $color->id, 'brand_id' => $brand->id, 'is_active' => true,
        ]);
    }

    private function produce(Outlet $outlet, Menu $menu, PlateColors $color, int $qty, string $date, ?string $finalStatus = null): void
    {
        for ($i = 0; $i < $qty; $i++) {
            ProductionItem::create([
                'menu_id'      => $menu->id,
                'outlet_id'    => $outlet->id,
                'plate_color'  => $color->id,
                'quantity'     => 1,
                'produced_at'  => $date . ' 09:00:00',
                'expires_at'   => $date . ' 10:00:00',
                'belt_status'  => 'fresh',
                'final_status' => $finalStatus,
                'sold_at'      => $finalStatus === 'sold' ? $date . ' 09:30:00' : null,
                'wasted_at'    => $finalStatus === 'waste' ? $date . ' 09:30:00' : null,
            ]);
        }
    }

    private function waste(Outlet $outlet, Menu $menu, PlateColors $color, int $qty, string $date): void
    {
        WasteRecord::create([
            'menu_id'     => $menu->id,
            'outlet_id'   => $outlet->id,
            'plate_color' => $color->id,
            'quantity'    => $qty,
            'reason'      => 'Expired',
            'recorded_at' => $date . ' 10:00:00',
        ]);
    }

    // ==================================================================
    // WASTE ANALYSIS — laporan yang paling langsung berujung ke rupiah
    // ==================================================================

    public function test_waste_cost_uses_the_price_of_the_outlets_own_brand(): void
    {
        $date = '2026-08-14';

        $this->produce($this->bandung, $this->menuMaharasa, $this->merahMaharasa, 10, $date);
        $this->waste($this->bandung, $this->menuMaharasa, $this->merahMaharasa, 2, $date);

        // Outlet lain, warna bernama sama, harga hampir 1,5x lipat.
        $this->produce($this->surabaya, $this->menuKatsuri, $this->merahKatsuri, 10, $date);
        $this->waste($this->surabaya, $this->menuKatsuri, $this->merahKatsuri, 2, $date);

        $bandung = $this->getJson(
            "/api/reports/waste-analysis?outletId={$this->bandung->id}&startDate={$date}&endDate={$date}"
        )->assertOk();

        $surabaya = $this->getJson(
            "/api/reports/waste-analysis?outletId={$this->surabaya->id}&startDate={$date}&endDate={$date}"
        )->assertOk();

        // 2 x 15.000 vs 2 x 21.000. Kalau harga diambil dari warna yang salah,
        // angka ini yang bohong.
        $this->assertSame(30000.0, (float) $bandung->json('data.wasteCost'));
        $this->assertSame(42000.0, (float) $surabaya->json('data.wasteCost'));
    }

    public function test_waste_analysis_lists_only_the_outlets_own_plate_colour(): void
    {
        $date = '2026-08-14';

        $this->produce($this->bandung, $this->menuMaharasa, $this->merahMaharasa, 5, $date);
        $this->waste($this->bandung, $this->menuMaharasa, $this->merahMaharasa, 1, $date);
        $this->produce($this->surabaya, $this->menuKatsuri, $this->merahKatsuri, 5, $date);
        $this->waste($this->surabaya, $this->menuKatsuri, $this->merahKatsuri, 3, $date);

        $response = $this->getJson(
            "/api/reports/waste-analysis?outletId={$this->bandung->id}&startDate={$date}&endDate={$date}"
        )->assertOk();

        $rows = collect($response->json('data.byPlateColor'));

        $this->assertCount(1, $rows);
        $this->assertSame($this->merahMaharasa->id, $rows->first()['plateColorId']);
        // 1, bukan 4 — waste Katsuri tidak boleh ikut terjumlah.
        $this->assertSame(1, $rows->first()['wasteCount']);
        $this->assertSame(1, (int) $response->json('data.totalWaste'));
    }

    // ==================================================================
    // POS COMPARISON — sumber angka untuk sales input
    // ==================================================================

    /**
     * Lewat service, bukan lewat /api/reports/pos-data.
     *
     * Endpoint itu memanggil ProductionItemService::getSoldItem(), yang meng-JOIN
     * dengan cast `plate_colors.id::text` — sintaks khusus PostgreSQL yang tidak
     * jalan di SQLite. Batasan lama yang sudah dicatat di POSServiceTest, bukan
     * soal brand. Bagian yang memang mau diuji di sini — pemetaan POS ke warna —
     * ada di POSService dan tidak butuh JOIN itu.
     */
    public function test_pos_data_for_closing_keeps_two_brands_apart(): void
    {
        $date = '2026-08-14';

        POSData::create([
            'id' => (string) Str::uuid(),
            'plate_color_id' => $this->merahMaharasa->id,
            'outlet_id' => $this->bandung->id,
            'date' => $date,
            'sold' => 4,
        ]);
        POSData::create([
            'id' => (string) Str::uuid(),
            'plate_color_id' => $this->merahKatsuri->id,
            'outlet_id' => $this->surabaya->id,
            'date' => $date,
            'sold' => 9,
        ]);

        $rows = app(\App\Services\POSService::class)
            ->getPosDataForClosing($this->bandung->id, $date);

        $this->assertCount(1, $rows);
        $this->assertSame($this->merahMaharasa->id, $rows->first()->plate_color_id);
        $this->assertSame(4, (int) $rows->first()->sold);
        // Nama warnanya identik di kedua brand — yang membedakan hanya id.
        $this->assertSame('Merah', $rows->first()->plateColor->platename);
        $this->assertSame(15000.0, (float) $rows->first()->plateColor->price);
    }

    // ==================================================================
    // DAILY SUMMARY + CLOSING REPORT
    // ==================================================================

    public function test_daily_summary_only_reports_the_requested_outlet(): void
    {
        $date = '2026-08-14';

        $this->postJson('/api/sales', [
            'outlet_id' => $this->bandung->id,
            'date'      => $date,
            'status'    => 'submitted',
            'items'     => [[
                'plate_color_id'   => $this->merahMaharasa->id,
                'pos_sold'         => 10,
                'production_sold'  => 10,
                'production_waste' => 1,
                'adjustment'       => 0,
                'compensation'     => 0,
            ]],
        ])->assertSuccessful();

        $this->postJson('/api/sales', [
            'outlet_id' => $this->surabaya->id,
            'date'      => $date,
            'status'    => 'submitted',
            'items'     => [[
                'plate_color_id'   => $this->merahKatsuri->id,
                'pos_sold'         => 99,
                'production_sold'  => 99,
                'production_waste' => 9,
                'adjustment'       => 0,
                'compensation'     => 0,
            ]],
        ])->assertSuccessful();

        $response = $this->getJson(
            "/api/reports/daily-summary?outletId={$this->bandung->id}&date={$date}"
        )->assertOk();

        $items = collect($response->json('data.items'));

        $this->assertCount(1, $items);
        $this->assertSame($this->merahMaharasa->id, $items->first()['plateColorId']);
        // 10, bukan 109.
        $this->assertSame(10, (int) $response->json('data.totalPOS'));
        $this->assertSame(1, (int) $response->json('data.totalWaste'));
    }

    public function test_two_brands_may_have_a_closing_entry_for_the_same_colour_name(): void
    {
        $date = '2026-08-14';

        foreach ([[$this->bandung, $this->merahMaharasa, 10], [$this->surabaya, $this->merahKatsuri, 20]] as [$outlet, $color, $sold]) {
            $this->postJson('/api/sales', [
                'outlet_id' => $outlet->id,
                'date'      => $date,
                'status'    => 'submitted',
                'items'     => [[
                    'plate_color_id'   => $color->id,
                    'pos_sold'         => $sold,
                    'production_sold'  => $sold,
                    'production_waste' => 0,
                    'adjustment'       => 0,
                    'compensation'     => 0,
                ]],
            ])->assertSuccessful();

            $this->postJson('/api/closing-reports/submit', [
                'outletId'        => $outlet->id,
                'date'            => $date,
                'kitchenLeader'   => 'Budi',
                'operationLeader' => 'Sari',
            ])->assertSuccessful();
        }

        // Unique-nya (closing_report_id, plate_color_id). Nama warna yang sama
        // di dua brand punya id berbeda, jadi keduanya lolos tanpa bertabrakan.
        $this->assertDatabaseCount('closing_reports', 2);
        $this->assertDatabaseHas('closing_report_entries', [
            'plate_color_id' => $this->merahMaharasa->id,
            'sold'           => 10,
        ]);
        $this->assertDatabaseHas('closing_report_entries', [
            'plate_color_id' => $this->merahKatsuri->id,
            'sold'           => 20,
        ]);
    }
}
