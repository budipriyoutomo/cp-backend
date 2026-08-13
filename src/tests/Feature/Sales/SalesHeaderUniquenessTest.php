<?php

namespace Tests\Feature\Sales;

use App\Models\SalesHeader;
use App\Models\SalesItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * One sales header per outlet per day.
 *
 * The Sales Input screen only ever POSTs — there is no update route — so every
 * save used to insert another header for the same day, and ClosingReportService
 * (which does firstOrFail() on outlet+date) picked one at random.
 */
class SalesHeaderUniquenessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        // Every route exercised here now sits behind auth:api.
        $this->actingAsRole('admin');
    }

    private function payload(string $outletId, string $colorId, array $overrides = []): array
    {
        return array_merge([
            'outlet_id' => $outletId,
            'date'      => '2026-06-17',
            'status'    => 'draft',
            'items'     => [
                [
                    'plate_color_id'   => $colorId,
                    'pos_sold'         => 10,
                    'production_sold'  => 5,
                    'production_waste' => 1,
                    'adjustment'       => 0,
                    'compensation'     => 0,
                ],
            ],
        ], $overrides);
    }

    public function test_saving_twice_for_the_same_day_updates_instead_of_duplicating(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))
            ->assertStatus(201);

        $first = SalesHeader::firstOrFail();

        // Same outlet + date, now submitted with a different POS count.
        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id, [
            'status' => 'submitted',
            'items'  => [[
                'plate_color_id'   => $color->id,
                'pos_sold'         => 42,
                'production_sold'  => 5,
                'production_waste' => 1,
                'adjustment'       => 0,
                'compensation'     => 0,
            ]],
        ]))->assertSuccessful();

        $this->assertSame(1, SalesHeader::count());
        $this->assertSame($first->id, SalesHeader::firstOrFail()->id);
        $this->assertSame('submitted', SalesHeader::firstOrFail()->status);
    }

    public function test_resaving_replaces_items_rather_than_appending(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))->assertStatus(201);
        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id, [
            'items' => [[
                'plate_color_id'   => $color->id,
                'pos_sold'         => 99,
                'production_sold'  => 1,
                'production_waste' => 0,
                'adjustment'       => 0,
                'compensation'     => 0,
            ]],
        ]))->assertSuccessful();

        $items = SalesItem::all();

        $this->assertCount(1, $items);
        $this->assertSame(99, (int) $items->first()->pos_sold);
        // selisih is recomputed on the update path too: 99 - (1 + 0 + 0).
        $this->assertSame(98, (int) $items->first()->selisih);
    }

    public function test_different_days_and_outlets_still_get_their_own_header(): void
    {
        $bandung = $this->createOutlet(['code' => 'BDG', 'name' => 'Bandung']);
        $jakarta = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $color   = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($bandung->id, $color->id))->assertStatus(201);
        $this->postJson('/api/sales', $this->payload($bandung->id, $color->id, ['date' => '2026-06-18']))
            ->assertStatus(201);
        $this->postJson('/api/sales', $this->payload($jakarta->id, $color->id))->assertStatus(201);

        $this->assertSame(3, SalesHeader::count());
    }

    public function test_the_database_rejects_a_duplicate_that_bypasses_the_service(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))->assertStatus(201);

        // Reuse the stored value rather than re-formatting the date: SQLite keeps
        // it as text ("2026-06-17 00:00:00") while PostgreSQL normalises a real
        // date column, and the index compares whatever is actually stored.
        $stored = DB::table('sales_headers')->where('outlet_id', $outlet->id)->first();

        $this->expectException(QueryException::class);

        // Raw insert, skipping SalesService entirely — the partial unique index
        // is the last line of defence.
        DB::table('sales_headers')->insert([
            'id'         => (string) Str::uuid(),
            'outlet_id'  => $outlet->id,
            'date'       => $stored->date,
            'status'     => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sales_cannot_be_rewritten_under_a_submitted_closing_report(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))->assertStatus(201);

        \App\Models\ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'submitted',
        ]);

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id, [
            'items' => [[
                'plate_color_id'   => $color->id,
                'pos_sold'         => 999,
                'production_sold'  => 1,
                'production_waste' => 0,
                'adjustment'       => 0,
                'compensation'     => 0,
            ]],
        ]))->assertStatus(422)->assertJsonPath('status', false);

        // The signed report's source data is untouched.
        $this->assertDatabaseHas('sales_items', ['pos_sold' => 10]);
        $this->assertDatabaseMissing('sales_items', ['pos_sold' => 999]);
    }

    public function test_a_draft_closing_report_does_not_block_sales(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))->assertStatus(201);

        \App\Models\ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'draft',
        ]);

        // Nothing is signed yet, so the operator can still correct the numbers.
        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id, [
            'items' => [[
                'plate_color_id'   => $color->id,
                'pos_sold'         => 12,
                'production_sold'  => 5,
                'production_waste' => 1,
                'adjustment'       => 0,
                'compensation'     => 0,
            ]],
        ]))->assertSuccessful();

        $this->assertDatabaseHas('sales_items', ['pos_sold' => 12]);
    }

    public function test_a_submitted_report_for_another_day_does_not_block(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        \App\Models\ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-18',
            'status'    => 'submitted',
        ]);

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))
            ->assertStatus(201);
    }

    public function test_a_soft_deleted_header_frees_up_its_day(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))->assertStatus(201);
        SalesHeader::firstOrFail()->delete();

        // The index is partial on deleted_at IS NULL, so the slot is free again.
        $this->postJson('/api/sales', $this->payload($outlet->id, $color->id))
            ->assertStatus(201);

        $this->assertSame(1, SalesHeader::count());
        $this->assertSame(2, SalesHeader::withTrashed()->count());
    }
}
