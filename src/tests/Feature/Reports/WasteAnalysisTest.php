<?php

namespace Tests\Feature\Reports;

use App\Models\Outlet;
use App\Models\Menu;
use App\Models\PlateColors;
use App\Models\ProductionItem;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class WasteAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private function waste(Outlet $outlet, Menu $menu, PlateColors $color, int $qty, string $reason, string $date): void
    {
        WasteRecord::create([
            'menu_id'     => $menu->id,
            'outlet_id'   => $outlet->id,
            'plate_color' => $color->id,
            'quantity'    => $qty,
            'reason'      => $reason,
            'recorded_at' => $date . ' 10:00:00',
        ]);
    }

    private function production(Outlet $outlet, Menu $menu, PlateColors $color, int $qty, string $date): void
    {
        for ($i = 0; $i < $qty; $i++) {
            ProductionItem::create([
                'menu_id'     => $menu->id,
                'outlet_id'   => $outlet->id,
                'plate_color' => $color->id,
                'quantity'    => 1,
                'produced_at' => $date . ' 09:00:00',
                'expires_at'  => $date . ' 10:00:00',
                'belt_status' => 'fresh',
            ]);
        }
    }

    public function test_waste_analysis_aggregates_totals_cost_and_breakdowns(): void
    {
        $outlet = $this->createOutlet();
        $red    = $this->createPlateColor(['platename' => 'Merah', 'price' => 10000]);
        $blue   = $this->createPlateColor(['platename' => 'Biru', 'price' => 20000]);
        $menu   = $this->createMenu($red);

        // Red: 3 waste (Expiration), Blue: 2 waste (Damaged) on 2026-06-10
        $this->waste($outlet, $menu, $red, 2, 'Expiration', '2026-06-10');
        $this->waste($outlet, $menu, $red, 1, 'Damaged', '2026-06-10');
        $this->waste($outlet, $menu, $blue, 2, 'Damaged', '2026-06-11');

        // Production: red 30, blue 10 within range
        $this->production($outlet, $menu, $red, 30, '2026-06-10');
        $this->production($outlet, $menu, $blue, 10, '2026-06-11');

        $response = $this->getJson(
            '/api/reports/waste-analysis?outletId=' . $outlet->id . '&startDate=2026-06-10&endDate=2026-06-11'
        );

        $response->assertOk()->assertJsonPath('status', true);
        $data = $response->json('data');

        // totals
        $this->assertSame(5, $data['totalWaste']);          // 2+1+2
        $this->assertSame(40, $data['totalProduction']);    // 30+10
        $this->assertEquals(12.5, $data['wastePercentage']); // 5/40*100
        // cost = red 3*10000 + blue 2*20000 = 30000 + 40000 = 70000
        $this->assertEquals(70000, $data['wasteCost']);

        // by reason: Damaged (1+2=3), Expiration (2) -> top reason Damaged
        $this->assertSame('Damaged', $data['topReason']);
        $this->assertCount(2, $data['byReason']);
        $this->assertSame('Damaged', $data['byReason'][0]['reason']);
        $this->assertSame(3, $data['byReason'][0]['count']);

        // by plate color: red waste 3, blue waste 2
        $this->assertCount(2, $data['byPlateColor']);
        $red_row = collect($data['byPlateColor'])->firstWhere('plateColorName', 'Merah');
        $this->assertSame(3, $red_row['wasteCount']);
        $this->assertSame(30, $red_row['productionCount']);
        $this->assertEquals(10.0, $red_row['wastePercentage']); // 3/30

        // by day trend has two days
        $this->assertCount(2, $data['byDay']);
    }

    public function test_waste_analysis_excludes_other_outlets_and_dates(): void
    {
        $outlet = $this->createOutlet(['code' => 'BDG']);
        $other  = $this->createOutlet(['code' => 'JKT']);
        $color  = $this->createPlateColor(['platename' => 'Merah', 'price' => 10000]);
        $menu   = $this->createMenu($color);

        $this->waste($outlet, $menu, $color, 5, 'Expiration', '2026-06-10'); // in range, target outlet
        $this->waste($other, $menu, $color, 9, 'Expiration', '2026-06-10');  // other outlet
        $this->waste($outlet, $menu, $color, 7, 'Expiration', '2026-01-01'); // out of range

        $data = $this->getJson(
            '/api/reports/waste-analysis?outletId=' . $outlet->id . '&startDate=2026-06-10&endDate=2026-06-11'
        )->assertOk()->json('data');

        $this->assertSame(5, $data['totalWaste']);
    }

    public function test_waste_analysis_returns_zeros_when_no_data(): void
    {
        $outlet = $this->createOutlet();

        $data = $this->getJson(
            '/api/reports/waste-analysis?outletId=' . $outlet->id . '&startDate=2026-06-10&endDate=2026-06-11'
        )->assertOk()->json('data');

        $this->assertSame(0, $data['totalWaste']);
        $this->assertSame(0, $data['totalProduction']);
        $this->assertEquals(0, $data['wastePercentage']);
        $this->assertEquals(0, $data['wasteCost']);
        $this->assertNull($data['topReason']);
        $this->assertCount(0, $data['byPlateColor']);
        $this->assertCount(0, $data['byReason']);
    }

    public function test_waste_analysis_validates_params(): void
    {
        $this->getJson('/api/reports/waste-analysis')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId', 'startDate', 'endDate']);
    }

    public function test_waste_analysis_rejects_end_before_start(): void
    {
        $outlet = $this->createOutlet();

        $this->getJson(
            '/api/reports/waste-analysis?outletId=' . $outlet->id . '&startDate=2026-06-11&endDate=2026-06-10'
        )->assertStatus(422)->assertJsonValidationErrors(['endDate']);
    }
}
