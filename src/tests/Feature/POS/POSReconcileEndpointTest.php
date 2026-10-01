<?php

namespace Tests\Feature\POS;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\POSData;
use App\Models\PlateColors;
use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Jalur sukses GET /api/reports/pos-data — angka yang dipakai layar Sales Input.
 *
 * Dulu tidak bisa diuji: perbandingannya meng-JOIN dengan cast
 * `plate_colors.id::text` yang hanya jalan di PostgreSQL. Sekarang nama warna
 * diresolusi di PHP (POSService::reconcile), jadi test ini jalan di SQLite.
 */
class POSReconcileEndpointTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const DATE = '2026-06-17';

    private Outlet $outlet;
    private PlateColors $merah;
    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('operation');

        $this->outlet = $this->createOutlet(['code' => 'BDG']);
        $this->merah  = $this->createPlateColor(['platename' => 'Merah']);
        $this->menu   = $this->createMenu($this->merah);
    }

    private function pos(Outlet $outlet, PlateColors $color, int $sold, string $date = self::DATE): void
    {
        POSData::create([
            'id'             => (string) Str::uuid(),
            'plate_color_id' => $color->id,
            'outlet_id'      => $outlet->id,
            'date'           => $date,
            'sold'           => $sold,
        ]);
    }

    private function closed(Outlet $outlet, Menu $menu, string $status, int $qty, array $overrides = []): void
    {
        for ($i = 0; $i < $qty; $i++) {
            $this->createProductionItem($outlet, $menu, array_merge([
                'produced_at'  => self::DATE . ' 09:00:00',
                'expires_at'   => self::DATE . ' 10:00:00',
                'final_status' => $status,
                'sold_at'      => $status === 'sold' ? self::DATE . ' 09:30:00' : null,
                'wasted_at'    => $status === 'waste' ? self::DATE . ' 09:30:00' : null,
            ], $overrides));
        }
    }

    private function fetch(string $date = self::DATE)
    {
        return $this->getJson("/api/reports/pos-data?outletId={$this->outlet->id}&date={$date}");
    }

    public function test_compares_pos_with_production_per_plate_color(): void
    {
        $this->pos($this->outlet, $this->merah, 10);
        $this->closed($this->outlet, $this->menu, 'sold', 8);
        $this->closed($this->outlet, $this->menu, 'waste', 1);

        $this->fetch()
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'plateColorId'    => $this->merah->id,
                'plateColorName'  => 'Merah',
                'posSold'         => 10,
                'productionSold'  => 8,
                'productionWaste' => 1,
                'selisih'         => 2,
            ]);
    }

    public function test_lists_colours_that_only_one_side_has(): void
    {
        $biru     = $this->createPlateColor(['platename' => 'Biru']);
        $menuBiru = $this->createMenu($biru, ['menuname' => 'Tuna']);

        // Merah hanya di POS, Biru hanya di produksi.
        $this->pos($this->outlet, $this->merah, 3);
        $this->closed($this->outlet, $menuBiru, 'sold', 2);

        $rows = collect($this->fetch()->assertOk()->json('data'))->keyBy('plateColorName');

        $this->assertCount(2, $rows);
        $this->assertSame([3, 0, 3], [$rows['Merah']['posSold'], $rows['Merah']['productionSold'], $rows['Merah']['selisih']]);
        $this->assertSame([0, 2, -2], [$rows['Biru']['posSold'], $rows['Biru']['productionSold'], $rows['Biru']['selisih']]);
    }

    public function test_ignores_other_outlets_and_other_dates(): void
    {
        $jakarta = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);

        $this->pos($this->outlet, $this->merah, 5);
        $this->pos($jakarta, $this->merah, 50);
        $this->pos($this->outlet, $this->merah, 70, '2026-06-16');
        $this->closed($this->outlet, $this->menu, 'sold', 4);
        $this->closed($jakarta, $this->menu, 'sold', 40);

        $this->fetch()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.posSold', 5)
            ->assertJsonPath('data.0.productionSold', 4);
    }

    public function test_still_names_a_soft_deleted_plate_color(): void
    {
        $this->pos($this->outlet, $this->merah, 2);
        $this->closed($this->outlet, $this->menu, 'sold', 2);
        $this->merah->delete();

        $this->fetch()
            ->assertOk()
            ->assertJsonPath('data.0.plateColorName', 'Merah')
            ->assertJsonPath('data.0.selisih', 0);
    }

    /**
     * Versi lama membuang piring seperti ini diam-diam (JOIN → id NULL →
     * tersaring), jadi angka produksi yang direkonsiliasi kurang tanpa tanda.
     */
    public function test_refuses_production_items_with_an_unknown_plate_color(): void
    {
        $this->pos($this->outlet, $this->merah, 3);
        $this->closed($this->outlet, $this->menu, 'sold', 2);
        $this->closed($this->outlet, $this->menu, 'sold', 1, ['plate_color' => 'Blue']);

        $this->fetch()
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonFragment(['message' => 'Ada plate color yang tidak dikenal di master: Blue. '
                . 'Rapikan data produksi tanggal ini sebelum mengambil data POS.']);
    }

    /**
     * Piring hari lalu yang tertinggal ditutup saat endpoint dipanggil, dan
     * waste-nya jatuh ke hari produksinya — jadi ikut terhitung di tanggal itu.
     */
    public function test_carry_over_waste_counts_on_its_production_day(): void
    {
        $this->createProductionItem($this->outlet, $this->menu, [
            'produced_at' => self::DATE . ' 09:00:00',
            'expires_at'  => self::DATE . ' 10:00:00',
        ]);

        $this->fetch()
            ->assertOk()
            ->assertJsonPath('data.0.productionWaste', 1)
            ->assertJsonPath('data.0.productionSold', 0);

        $this->assertSame('waste', ProductionItem::first()->final_status);
    }
}
