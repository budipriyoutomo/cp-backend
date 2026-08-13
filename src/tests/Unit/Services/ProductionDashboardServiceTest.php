<?php

namespace Tests\Unit\Services;

use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Services\Production\ProductionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * The kitchen dashboard compares today's plan against what actually happened.
 * `targetToday` is the plan side, and it was silently broken: Carbon is
 * mutable, so whereBetween([$today->startOfDay(), $today->endOfDay()]) passed
 * one and the same instance twice and both bounds collapsed to 23:59:59.
 */
class ProductionDashboardServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private function service(): ProductionDashboardService
    {
        return app(ProductionDashboardService::class);
    }

    private function planFor(
        string $outletId,
        string $date,
        string $plateColorId,
        int $qty,
        string $timeSlot = '08:00-09:00'
    ): void {
        // production_plans is unique on (outlet_id, date, time_slot).
        $plan = ProductionPlan::create([
            'date'      => $date,
            'time_slot' => $timeSlot,
            'outlet_id' => $outletId,
        ]);

        ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'plate_color'        => $plateColorId,
            'qty'                => $qty,
        ]);
    }

    public function test_target_today_reads_the_plan_for_today(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Merah']);

        $this->planFor($outlet->id, today()->toDateString(), $color->id, 12);

        $stats = $this->service()->stats($outlet->id);

        $this->assertCount(1, $stats);
        $this->assertSame('Merah', $stats[0]['plateColor']);
        $this->assertSame(12, (int) $stats[0]['targetToday']);
    }

    public function test_target_today_sums_every_time_slot(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Merah']);
        $date   = today()->toDateString();

        $this->planFor($outlet->id, $date, $color->id, 5, '08:00-09:00');
        $this->planFor($outlet->id, $date, $color->id, 7, '09:00-10:00');

        $this->assertSame(12, (int) $this->service()->stats($outlet->id)[0]['targetToday']);
    }

    public function test_target_today_excludes_other_days_and_other_outlets(): void
    {
        $bandung = $this->createOutlet(['code' => 'BDG', 'name' => 'Bandung']);
        $jakarta = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $color   = $this->createPlateColor(['platename' => 'Merah']);

        $this->planFor($bandung->id, today()->toDateString(), $color->id, 4);
        $this->planFor($bandung->id, today()->subDay()->toDateString(), $color->id, 99);
        $this->planFor($bandung->id, today()->addDay()->toDateString(), $color->id, 99);
        $this->planFor($jakarta->id, today()->toDateString(), $color->id, 99);

        $this->assertSame(4, (int) $this->service()->stats($bandung->id)[0]['targetToday']);
    }

    public function test_target_today_is_zero_when_nothing_is_planned(): void
    {
        $outlet = $this->createOutlet();
        $this->createPlateColor(['platename' => 'Merah']);

        $this->assertSame(0, (int) $this->service()->stats($outlet->id)[0]['targetToday']);
    }

    public function test_produced_and_sold_are_counted_per_plate_color(): void
    {
        $outlet = $this->createOutlet();
        $merah  = $this->createPlateColor(['platename' => 'Merah']);
        $this->createPlateColor(['platename' => 'Biru']);
        $menu   = $this->createMenu($merah);

        $this->createProductionItem($outlet, $menu);
        $this->createProductionItem($outlet, $menu, [
            'final_status' => 'sold',
            'sold_at'      => now(),
        ]);

        $stats  = $this->service()->stats($outlet->id)->keyBy('plateColor');

        $this->assertSame(2, (int) $stats['Merah']['produced']);
        $this->assertSame(1, (int) $stats['Merah']['sold']);
        // A colour with no activity still appears, at zero.
        $this->assertSame(0, (int) $stats['Biru']['produced']);
    }

    public function test_waste_is_attributed_by_wasted_at_not_expires_at(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        // Wasted today — counts.
        $this->createProductionItem($outlet, $menu, [
            'final_status' => 'waste',
            'wasted_at'    => now(),
        ]);

        // Carry-over plate: it expired today but autoWasteCarryOver() attributes
        // it back to its production day, so today's dashboard must not claim it.
        // The old code keyed off expires_at and double-counted exactly this row.
        $this->createProductionItem($outlet, $menu, [
            'produced_at'  => now()->subDay(),
            'expires_at'   => now(),
            'final_status' => 'waste',
            'wasted_at'    => now()->subDay(),
        ]);

        $this->assertSame(1, (int) $this->service()->stats($outlet->id)[0]['waste']);
    }

    public function test_expiring_soon_counts_unresolved_expired_plates(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $this->createProductionItem($outlet, $menu, [
            'belt_status' => 'expired',
            'expires_at'  => now(),
        ]);
        // Already resolved, so it is no longer waiting for a decision.
        $this->createProductionItem($outlet, $menu, [
            'belt_status'  => 'expired',
            'expires_at'   => now(),
            'final_status' => 'waste',
            'wasted_at'    => now(),
        ]);

        $this->assertSame(1, (int) $this->service()->stats($outlet->id)[0]['expiringSoon']);
    }

    public function test_stats_are_scoped_to_the_requested_outlet(): void
    {
        $bandung = $this->createOutlet(['code' => 'BDG', 'name' => 'Bandung']);
        $jakarta = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu    = $this->createMenu();

        $this->createProductionItem($jakarta, $menu);

        $stats = $this->service()->stats($bandung->id);

        $this->assertSame(0, (int) $stats[0]['produced']);
        $this->assertSame($bandung->id, $stats[0]['outletId']);
    }
}
