<?php

namespace Tests\Feature\Reports;

use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class DailySummaryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private const DATE = '2026-06-17';

    private function seedSalesItem(SalesHeader $header, PlateColors $color, array $values): SalesItem
    {
        return SalesItem::create(array_merge([
            'sales_id'         => $header->id,
            'plate_color_id'   => $color->id,
            'pos_sold'         => 0,
            'production_sold'  => 0,
            'production_waste' => 0,
            'adjustment'       => 0,
            'compensation'     => 0,
            'selisih'          => 0,
        ], $values));
    }

    public function test_daily_summary_aggregates_totals_across_items(): void
    {
        $outlet = $this->createOutlet(['name' => 'Bandung']);
        $red    = $this->createPlateColor(['platename' => 'Merah']);
        $green  = $this->createPlateColor(['platename' => 'Hijau']);

        $header = SalesHeader::create([
            'outlet_id' => $outlet->id,
            'date'      => self::DATE,
            'status'    => 'submitted',
        ]);

        $this->seedSalesItem($header, $red, [
            'pos_sold' => 10, 'production_sold' => 8, 'production_waste' => 2,
            'adjustment' => 1, 'compensation' => 1, 'selisih' => 0,
        ]);
        $this->seedSalesItem($header, $green, [
            'pos_sold' => 5, 'production_sold' => 4, 'production_waste' => 1,
            'adjustment' => 0, 'compensation' => 0, 'selisih' => 1,
        ]);

        $response = $this->getJson('/api/reports/daily-summary?outletId=' . $outlet->id . '&date=' . self::DATE);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.outletId', $outlet->id)
            ->assertJsonPath('data.outletName', 'Bandung')
            ->assertJsonCount(2, 'data.items');

        $data = $response->json('data');
        $this->assertEquals(15, $data['totalPOS']);
        $this->assertEquals(12, $data['totalProduction']);
        $this->assertEquals(3, $data['totalWaste']);
        $this->assertEquals(1, $data['totalAdjustment']);
        $this->assertEquals(1, $data['totalCompensation']);
        $this->assertEquals(1, $data['totalSelisih']);
    }

    public function test_daily_summary_returns_zeros_when_no_sales(): void
    {
        $outlet = $this->createOutlet();

        $response = $this->getJson('/api/reports/daily-summary?outletId=' . $outlet->id . '&date=' . self::DATE);

        $response->assertOk()
            ->assertJsonPath('data.outletId', null)
            ->assertJsonPath('data.outletName', null)
            ->assertJsonCount(0, 'data.items');

        $data = $response->json('data');
        $this->assertEquals(0, $data['totalPOS']);
        $this->assertEquals(0, $data['totalWaste']);
        $this->assertEquals(0, $data['totalSelisih']);
    }

    public function test_daily_summary_only_includes_matching_date(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $header = SalesHeader::create([
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-10',
            'status'    => 'submitted',
        ]);
        $this->seedSalesItem($header, $color, ['pos_sold' => 99]);

        // Querying a different date should not pick up the 2026-06-10 sales.
        $response = $this->getJson('/api/reports/daily-summary?outletId=' . $outlet->id . '&date=' . self::DATE);

        $response->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertEquals(0, $response->json('data.totalPOS'));
    }

    public function test_daily_summary_validates_required_params(): void
    {
        $this->getJson('/api/reports/daily-summary')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId', 'date']);
    }

    public function test_daily_summary_rejects_non_uuid_outlet(): void
    {
        $this->getJson('/api/reports/daily-summary?outletId=not-a-uuid&date=' . self::DATE)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_daily_summary_rejects_invalid_date(): void
    {
        $outlet = $this->createOutlet();

        $this->getJson('/api/reports/daily-summary?outletId=' . $outlet->id . '&date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }
}
