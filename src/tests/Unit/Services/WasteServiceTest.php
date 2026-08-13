<?php

namespace Tests\Unit\Services;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\WasteRecord;
use App\Services\Production\WasteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Backs GET /waste/summary — the waste side of the daily reconciliation.
 *
 * waste_records.plate_color stores a plate color UUID, so every name shown to
 * an operator has to be resolved against the master table. It used to be
 * `ucfirst($item->plate_color)`, which printed a capitalised UUID.
 */
class WasteServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private function service(): WasteService
    {
        return app(WasteService::class);
    }

    /** @var array<string, \App\Models\Menu> menu per plate color, waste_records.menu_id is NOT NULL */
    private array $menus = [];

    private function menuFor(PlateColors $color): Menu
    {
        return $this->menus[$color->id] ??= $this->createMenu($color, [
            'menuname' => 'Sushi ' . $color->platename,
        ]);
    }

    private function wasteRecord(
        Outlet $outlet,
        PlateColors $color,
        int $quantity = 1,
        array $overrides = []
    ): WasteRecord {
        return WasteRecord::create(array_merge([
            'outlet_id'   => $outlet->id,
            'menu_id'     => $this->menuFor($color)->id,
            'plate_color' => $color->id,
            'quantity'    => $quantity,
            'reason'      => 'Basi',
            'recorded_at' => now(),
        ], $overrides));
    }

    public function test_summary_resolves_plate_color_names_from_the_master(): void
    {
        $outlet = $this->createOutlet();
        $merah  = $this->createPlateColor(['platename' => 'Merah']);

        $this->wasteRecord($outlet, $merah, 3);

        $summary = $this->service()->getSummary(['outletId' => $outlet->id]);

        $row = $summary['byPlateColor'][0];

        $this->assertSame($merah->id, $row['plateColorId']);
        // Regression: this used to be ucfirst() of the raw UUID.
        $this->assertSame('Merah', $row['plateColorName']);
        $this->assertStringNotContainsString('-', $row['plateColorName']);
    }

    public function test_summary_falls_back_to_unknown_for_an_orphan_plate_color(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Merah']);

        // waste_records.plate_color has no foreign key, so a historical record
        // can point at a plate color that is no longer in the master.
        $this->wasteRecord($outlet, $color, 1, [
            'plate_color' => '11111111-1111-1111-1111-111111111111',
        ]);

        $row = $this->service()->getSummary(['outletId' => $outlet->id])['byPlateColor'][0];

        $this->assertSame('Unknown', $row['plateColorName']);
    }

    public function test_summary_counts_waste_and_production_per_plate_color(): void
    {
        $outlet = $this->createOutlet();
        $merah  = $this->createPlateColor(['platename' => 'Merah']);
        $menu   = $this->createMenu($merah);

        $this->createProductionItem($outlet, $menu);
        $this->createProductionItem($outlet, $menu);
        $this->createProductionItem($outlet, $menu);
        $this->createProductionItem($outlet, $menu);
        $this->wasteRecord($outlet, $merah, 1);

        $summary = $this->service()->getSummary([
            'outletId' => $outlet->id,
            'date'     => now()->toDateString(),
        ]);

        $this->assertSame(1, $summary['totalWaste']);
        $this->assertSame(4, $summary['totalProduction']);
        $this->assertSame(25.0, $summary['wastePercentage']);

        $row = $summary['byPlateColor'][0];
        $this->assertSame(1, $row['wasteCount']);
        $this->assertSame(4, $row['productionCount']);
    }

    public function test_summary_reports_zero_percent_when_nothing_was_produced(): void
    {
        $outlet = $this->createOutlet();

        $summary = $this->service()->getSummary(['outletId' => $outlet->id]);

        $this->assertSame(0, $summary['totalWaste']);
        $this->assertSame(0, $summary['totalProduction']);
        // Guards against a division by zero.
        $this->assertSame(0, $summary['wastePercentage']);
        $this->assertCount(0, $summary['byPlateColor']);
    }

    public function test_summary_is_scoped_to_outlet_and_date(): void
    {
        $bandung = $this->createOutlet(['code' => 'BDG', 'name' => 'Bandung']);
        $jakarta = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $merah   = $this->createPlateColor(['platename' => 'Merah']);

        $this->wasteRecord($bandung, $merah, 2);
        $this->wasteRecord($jakarta, $merah, 9);
        $this->wasteRecord($bandung, $merah, 9, ['recorded_at' => now()->subDays(3)]);

        $summary = $this->service()->getSummary([
            'outletId' => $bandung->id,
            'date'     => now()->toDateString(),
        ]);

        $this->assertSame(2, $summary['totalWaste']);
    }

    public function test_summary_groups_multiple_plate_colors(): void
    {
        $outlet = $this->createOutlet();
        $merah  = $this->createPlateColor(['platename' => 'Merah']);
        $biru   = $this->createPlateColor(['platename' => 'Biru']);

        $this->wasteRecord($outlet, $merah, 2);
        $this->wasteRecord($outlet, $merah, 1);
        $this->wasteRecord($outlet, $biru, 4);

        $summary = $this->service()->getSummary(['outletId' => $outlet->id]);

        $byName = collect($summary['byPlateColor'])->keyBy('plateColorName');

        $this->assertSame(3, $byName['Merah']['wasteCount']);
        $this->assertSame(4, $byName['Biru']['wasteCount']);
        $this->assertSame(7, $summary['totalWaste']);
    }

    public function test_get_all_filters_by_outlet_date_and_plate_color(): void
    {
        $outlet = $this->createOutlet();
        $merah  = $this->createPlateColor(['platename' => 'Merah']);
        $biru   = $this->createPlateColor(['platename' => 'Biru']);

        $mine = $this->wasteRecord($outlet, $merah, 1);
        $this->wasteRecord($outlet, $biru, 1);

        $rows = $this->service()->getAll([
            'outletId'     => $outlet->id,
            'date'         => now()->toDateString(),
            'plateColorId' => $merah->id,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows->first()->id);
        // The relation is eager loaded so the API never N+1s.
        $this->assertTrue($rows->first()->relationLoaded('plateColor'));
    }
}
