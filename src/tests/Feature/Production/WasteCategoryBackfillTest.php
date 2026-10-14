<?php

namespace Tests\Feature\Production;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\WasteCategory;
use App\Models\WasteReason;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * `waste:backfill-category` — mengisi kategori waste_records lama dari teks.
 */
class WasteCategoryBackfillTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private Outlet $outlet;
    private Menu $menu;
    private WasteCategory $default;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet  = $this->createOutlet();
        $this->menu    = $this->createMenu();
        $this->default = WasteCategory::where('code', WasteCategory::DEFAULT_CODE)->firstOrFail();

        WasteReason::create(['reason_name' => 'Expired', 'waste_category_id' => $this->default->id]);
    }

    /** Baris waste gaya lama: teks saja, tanpa snapshot. */
    private function legacy(string $reason, array $overrides = []): WasteRecord
    {
        return WasteRecord::create(array_merge([
            'menu_id'     => $this->menu->id,
            'outlet_id'   => $this->outlet->id,
            'plate_color' => $this->menu->plate_color_id,
            'quantity'    => 1,
            'reason'      => $reason,
            'recorded_at' => now()->subDays(3),
        ], $overrides));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->legacy('Expired');
        $this->legacy('Teks asing');

        $this->artisan('waste:backfill-category', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, WasteRecord::whereNotNull('waste_category_id')->count());
    }

    public function test_it_matches_master_names_and_the_texts_written_by_code(): void
    {
        $expired = $this->legacy(' expired ');
        $auto    = $this->legacy('Auto-waste: plate tidak diselesaikan pada hari produksi');
        $import  = $this->legacy('Import produksi backdate');
        $unknown = $this->legacy('Teks asing');

        $this->artisan('waste:backfill-category')->assertSuccessful();

        $autoReason   = WasteReason::where('reason_name', WasteReason::AUTO_WASTE)->firstOrFail();
        $importReason = WasteReason::where('reason_name', WasteReason::IMPORT_BACKDATE)->firstOrFail();

        $this->assertSame($this->default->id, $expired->fresh()->waste_category_id);
        $this->assertSame($autoReason->id, $auto->fresh()->waste_reason_id);
        $this->assertSame($importReason->id, $import->fresh()->waste_reason_id);
        $this->assertNull($unknown->fresh()->waste_category_id);
    }

    public function test_fallback_assigns_a_category_to_unmatched_texts_only(): void
    {
        $other   = WasteCategory::create(['code' => 'OTHER', 'category_name' => 'Lain-lain']);
        $expired = $this->legacy('Expired');
        $unknown = $this->legacy('Teks asing');

        $this->artisan('waste:backfill-category', ['--fallback' => 'other'])->assertSuccessful();

        $this->assertSame($this->default->id, $expired->fresh()->waste_category_id);
        $this->assertSame($other->id, $unknown->fresh()->waste_category_id);
        $this->assertNull($unknown->fresh()->waste_reason_id);
    }

    public function test_an_unknown_fallback_code_fails_without_writing(): void
    {
        $this->legacy('Teks asing');

        $this->artisan('waste:backfill-category', ['--fallback' => 'NOPE'])->assertFailed();

        $this->assertSame(0, WasteRecord::whereNotNull('waste_category_id')->count());
    }

    public function test_rows_that_already_have_a_category_are_never_touched(): void
    {
        $other = WasteCategory::create(['code' => 'OTHER', 'category_name' => 'Lain-lain']);
        $kept  = $this->legacy('Expired', ['waste_category_id' => $other->id]);

        $this->artisan('waste:backfill-category')->assertSuccessful();

        $this->assertSame($other->id, $kept->fresh()->waste_category_id);
    }
}
