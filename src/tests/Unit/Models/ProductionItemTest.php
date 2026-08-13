<?php

namespace Tests\Unit\Models;

use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Unit coverage for the two status axes on a plate.
 *
 * `belt_status` (fresh/warning/expired) is derived from time and may be
 * recomputed at any moment; `final_status` (sold/waste/null) is written once.
 * The helpers below must never touch `final_status` — an expired plate can
 * still be sold.
 */
class ProductionItemTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expiringIn(int $minutes, array $overrides = []): ProductionItem
    {
        return new ProductionItem(array_merge([
            'expires_at' => now()->addMinutes($minutes),
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | updateBeltStatus() — driven by expires_at
    |--------------------------------------------------------------------------
    */

    public function test_update_belt_status_marks_fresh_when_expiry_is_far(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        $this->assertSame('fresh', $this->expiringIn(60)->updateBeltStatus()->belt_status);
    }

    public function test_update_belt_status_marks_warning_within_the_last_fifteen_minutes(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        $this->assertSame('warning', $this->expiringIn(1)->updateBeltStatus()->belt_status);
        // Exactly 15 minutes out is still warning (`<=` boundary).
        $this->assertSame('warning', $this->expiringIn(15)->updateBeltStatus()->belt_status);
    }

    public function test_update_belt_status_is_fresh_just_outside_the_warning_window(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        $this->assertSame('fresh', $this->expiringIn(16)->updateBeltStatus()->belt_status);
    }

    public function test_update_belt_status_marks_expired_at_and_after_expiry(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        // Exactly at expiry counts as expired (`<=` boundary).
        $this->assertSame('expired', $this->expiringIn(0)->updateBeltStatus()->belt_status);
        $this->assertSame('expired', $this->expiringIn(-1)->updateBeltStatus()->belt_status);
    }

    public function test_update_belt_status_is_chainable_and_leaves_final_status_alone(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        $sold = $this->expiringIn(-30, ['final_status' => 'sold']);

        $returned = $sold->updateBeltStatus();

        $this->assertSame($sold, $returned);
        // An expired plate that was already sold stays sold.
        $this->assertSame('expired', $sold->belt_status);
        $this->assertSame('sold', $sold->final_status);
    }

    public function test_update_belt_status_does_not_persist_by_itself(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, [
            'expires_at'  => now()->subMinute(),
            'belt_status' => 'fresh',
        ]);

        $item->updateBeltStatus();

        $this->assertSame('expired', $item->belt_status);
        // Caller is responsible for save() — the row is untouched until then.
        $this->assertDatabaseHas('production_items', [
            'id'          => $item->id,
            'belt_status' => 'fresh',
        ]);

        $item->save();

        $this->assertDatabaseHas('production_items', [
            'id'          => $item->id,
            'belt_status' => 'expired',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | updateStatus() — legacy variant driven by produced_at + fixed 60 min
    |--------------------------------------------------------------------------
    */

    public function test_update_status_uses_a_fixed_sixty_minute_shelf_life(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        $producedAgo = fn (int $minutes) => (new ProductionItem([
            'produced_at' => now()->subMinutes($minutes),
        ]))->updateStatus()->belt_status;

        $this->assertSame('fresh', $producedAgo(0));
        $this->assertSame('fresh', $producedAgo(44));
        $this->assertSame('warning', $producedAgo(45));
        $this->assertSame('warning', $producedAgo(59));
        $this->assertSame('expired', $producedAgo(60));
        $this->assertSame('expired', $producedAgo(120));
    }

    /*
    |--------------------------------------------------------------------------
    | time_on_belt accessor
    |--------------------------------------------------------------------------
    */

    public function test_time_on_belt_returns_minutes_since_production(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        $item = new ProductionItem(['produced_at' => now()->subMinutes(37)]);

        $this->assertSame(37, $item->time_on_belt);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function test_belt_status_scopes_filter_by_status(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $fresh   = $this->createProductionItem($outlet, $menu, ['belt_status' => 'fresh']);
        $warning = $this->createProductionItem($outlet, $menu, ['belt_status' => 'warning']);
        $expired = $this->createProductionItem($outlet, $menu, ['belt_status' => 'expired']);

        $this->assertSame([$fresh->id], ProductionItem::beltFresh()->pluck('id')->all());
        $this->assertSame([$warning->id], ProductionItem::warning()->pluck('id')->all());
        $this->assertSame([$expired->id], ProductionItem::expired()->pluck('id')->all());

        // `active` = still on the belt, i.e. anything not expired.
        $active = ProductionItem::active()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fresh->id, $warning->id], $active);
    }

    public function test_for_outlet_scope_isolates_data_per_outlet(): void
    {
        $bandung = $this->createOutlet(['code' => 'BDG', 'name' => 'Bandung']);
        $jakarta = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu    = $this->createMenu();

        $mine = $this->createProductionItem($bandung, $menu);
        $this->createProductionItem($jakarta, $menu);

        $this->assertSame([$mine->id], ProductionItem::forOutlet($bandung->id)->pluck('id')->all());
    }

    /**
     * Regression guard for the rename. Eloquent resolves a real method before a
     * scope, so a scope called `fresh` or `outlet` would be shadowed by
     * Model::fresh() and by the outlet() relation — silently filtering nothing.
     * These two names must stay free of collisions.
     */
    public function test_scope_names_do_not_collide_with_real_methods(): void
    {
        $this->assertFalse(
            method_exists(ProductionItem::class, 'beltFresh'),
            'beltFresh must resolve as a scope, not as a real method.'
        );
        $this->assertFalse(
            method_exists(ProductionItem::class, 'forOutlet'),
            'forOutlet must resolve as a scope, not as a real method.'
        );

        // The names they replaced are exactly the ones that were taken.
        $this->assertTrue(method_exists(ProductionItem::class, 'fresh'));
        $this->assertTrue(method_exists(ProductionItem::class, 'outlet'));
    }

    /*
    |--------------------------------------------------------------------------
    | Casting
    |--------------------------------------------------------------------------
    */

    public function test_timestamp_columns_are_cast_to_carbon(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, [
            'final_status' => 'sold',
            'sold_at'      => now(),
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $item->produced_at);
        $this->assertInstanceOf(Carbon::class, $item->expires_at);
        $this->assertInstanceOf(Carbon::class, $item->sold_at);
        $this->assertNull($item->wasted_at);
    }
}
