<?php

namespace Tests\Feature\Production;

use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class ProductionItemTest extends TestCase
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

    public function test_produce_creates_one_item_per_quantity(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $response = $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 3,
            'outletId' => $outlet->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(3, 'data');

        $this->assertSame(3, ProductionItem::where('menu_id', $menu->id)->count());
        $this->assertDatabaseHas('production_items', [
            'menu_id'     => $menu->id,
            'outlet_id'   => $outlet->id,
            'plate_color' => $menu->plate_color_id,
            'quantity'    => 1,
        ]);
    }

    public function test_produce_validates_payload(): void
    {
        $this->postJson('/api/production/produce', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menuId', 'quantity', 'outletId']);
    }

    public function test_produce_rejects_non_existent_menu(): void
    {
        $outlet = $this->createOutlet();

        $this->postJson('/api/production/produce', [
            'menuId'   => '11111111-1111-1111-1111-111111111111',
            'quantity' => 1,
            'outletId' => $outlet->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['menuId']);
    }

    public function test_conveyor_returns_only_items_still_on_the_belt(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $fresh   = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour(), 'belt_status' => 'fresh']);
        $expired = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute(), 'belt_status' => 'fresh']);

        $response = $this->getJson('/api/production/conveyor?outletId=' . $outlet->id);

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fresh->id);

        // The past-due item is excluded because expires_at says so — the stored
        // belt_status is not consulted, and the GET writes nothing.
        $this->assertDatabaseHas('production_items', [
            'id'          => $expired->id,
            'belt_status' => 'fresh',
        ]);
    }

    public function test_conveyor_reports_belt_status_from_expiry_without_saving(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        // Stored value is stale: the scheduler has not caught up yet.
        $item = $this->createProductionItem($outlet, $menu, [
            'expires_at'  => now()->addMinutes(5),
            'belt_status' => 'fresh',
        ]);

        $this->getJson('/api/production/conveyor?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.beltStatus', 'warning');

        // The response is accurate, the row is untouched.
        $this->assertDatabaseHas('production_items', [
            'id'          => $item->id,
            'belt_status' => 'fresh',
        ]);
    }

    public function test_refresh_belt_status_command_updates_the_stored_column(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $expired = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute(), 'belt_status' => 'fresh']);
        $warning = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addMinutes(5), 'belt_status' => 'fresh']);
        $fresh   = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour(), 'belt_status' => 'expired']);
        $done    = $this->createProductionItem($outlet, $menu, [
            'expires_at'   => now()->subMinute(),
            'belt_status'  => 'fresh',
            'final_status' => 'sold',
            'sold_at'      => now(),
        ]);

        $this->artisan('production:refresh-belt-status')->assertSuccessful();

        $this->assertSame('expired', $expired->fresh()->belt_status);
        $this->assertSame('warning', $warning->fresh()->belt_status);
        $this->assertSame('fresh', $fresh->fresh()->belt_status);
        // Already finalised, so it is off the belt and left alone.
        $this->assertSame('fresh', $done->fresh()->belt_status);
    }

    public function test_expired_returns_only_expired_items_for_today(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $expired = $this->createProductionItem($outlet, $menu, [
            'belt_status' => 'expired',
            'expires_at'  => now(),
        ]);
        $this->createProductionItem($outlet, $menu, ['belt_status' => 'fresh']);

        $this->getJson('/api/production/expired?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expired->id);
    }

    public function test_update_expired_marks_item_sold(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, ['belt_status' => 'expired', 'expires_at' => now()->subMinute()]);

        $this->putJson("/api/production/expired/{$item->id}", ['status' => 'sold'])
            ->assertOk()
            ->assertJsonPath('message', 'Expired item updated successfully');

        $item->refresh();
        $this->assertSame('sold', $item->final_status);
        $this->assertNotNull($item->sold_at);
    }

    public function test_update_expired_marks_item_waste_and_records_it(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, ['belt_status' => 'expired', 'expires_at' => now()->subMinute()]);

        $this->putJson("/api/production/expired/{$item->id}", [
            'status' => 'waste',
            'notes'  => 'Basi',
        ])->assertOk();

        $item->refresh();
        $this->assertSame('waste', $item->final_status);
        $this->assertNotNull($item->wasted_at);

        $this->assertDatabaseHas('waste_records', [
            'production_item_id' => $item->id,
            'menu_id'            => $menu->id,
            'reason'             => 'Basi',
        ]);
    }

    public function test_update_expired_validates_status(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, ['belt_status' => 'expired', 'expires_at' => now()->subMinute()]);

        $this->putJson("/api/production/expired/{$item->id}", ['status' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_mark_sold_updates_items(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $this->postJson('/api/production/mark-sold', ['itemIds' => [$item->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Items marked as sold');

        $item->refresh();
        $this->assertSame('sold', $item->final_status);
        $this->assertNotNull($item->sold_at);
    }

    public function test_mark_waste_updates_items(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $this->postJson('/api/production/mark-waste', ['itemIds' => [$item->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Items marked as waste');

        $item->refresh();
        $this->assertSame('waste', $item->final_status);
        $this->assertNotNull($item->wasted_at);
    }

    public function test_mark_sold_rejects_items_from_previous_day(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, [
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);

        $this->postJson('/api/production/mark-sold', ['itemIds' => [$item->id]])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $item->refresh();
        $this->assertNull($item->final_status);
        $this->assertNull($item->sold_at);
    }

    public function test_update_expired_rejects_items_from_previous_day(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, [
            'belt_status' => 'expired',
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);

        $this->putJson("/api/production/expired/{$item->id}", ['status' => 'sold'])
            ->assertStatus(422);

        $item->refresh();
        $this->assertNull($item->final_status);
    }

    public function test_auto_waste_carry_over_wastes_yesterday_unresolved_items(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $yesterday = $this->createProductionItem($outlet, $menu, [
            'belt_status' => 'expired',
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);
        $today = $this->createProductionItem($outlet, $menu);

        $this->artisan('production:close-stale')->assertSuccessful();

        $yesterday->refresh();
        $this->assertSame('waste', $yesterday->final_status);
        $this->assertNotNull($yesterday->wasted_at);
        // Atribusi ke hari produksi (kemarin), bukan hari ini.
        $this->assertTrue($yesterday->wasted_at->isSameDay(now()->subDay()));
        $this->assertDatabaseHas('waste_records', [
            'production_item_id' => $yesterday->id,
        ]);

        // Plate hari ini tidak boleh ikut ter-waste.
        $today->refresh();
        $this->assertNull($today->final_status);
    }

    public function test_get_pos_data_blocked_when_unresolved_items_exist(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $this->createProductionItem($outlet, $menu); // produced today, belum sold/waste

        $this->getJson('/api/reports/pos-data?outletId=' . $outlet->id . '&date=' . now()->toDateString())
            ->assertStatus(422)
            ->assertJsonPath('status', false);
    }

    public function test_remove_expired_route_is_gone(): void
    {
        // POST /production/remove-expired only re-stamped belt_status without
        // any production-day guard, and nothing called it. Removed outright.
        $this->postJson('/api/production/remove-expired', ['itemIds' => []])
            ->assertNotFound();
    }
}
