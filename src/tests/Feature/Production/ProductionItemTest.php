<?php

namespace Tests\Feature\Production;

use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Every tablet polls these two lists every 30 seconds, so their cost has to
     * stay flat in the number of plates on the belt.
     *
     * ProductionItemResource reads `menu->menuname` and
     * `menu->plateColor->platename`. Neither list goes through buildQuery(), so
     * the `$relations` property does not apply to them — `$this->query()` is a
     * bare newQuery(). Without an explicit `with()` each row woke two more
     * queries, and a 100-plate belt turned one GET into ~201 round trips.
     */
    public function test_belt_lists_do_not_grow_queries_with_the_number_of_plates(): void
    {
        $outlet = $this->createOutlet();

        // Distinct menu + plate color per plate: shared ones would be resolved
        // from Eloquent's identity map and hide the N+1 this test guards.
        $seedPlates = function (int $count, int $offset) use ($outlet): void {
            for ($i = $offset; $i < $offset + $count; $i++) {
                $color = $this->createPlateColor(['platename' => "Warna {$i}"]);
                $menu  = $this->createMenu($color, [
                    'code'     => "MENU{$i}",
                    'menuname' => "Sushi {$i}",
                ]);

                // One plate still on the belt, one already past due, so both
                // endpoints see a growing list.
                $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour()]);
                $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);
            }
        };

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $measure = function (string $endpoint) use ($outlet, &$queries): int {
            $queries = 0;
            $this->getJson("/api/production/{$endpoint}?outletId={$outlet->id}")->assertOk();

            return $queries;
        };

        $seedPlates(1, 0);
        $conveyorWithOne = $measure('conveyor');
        $expiredWithOne  = $measure('expired');

        $seedPlates(9, 1);
        $conveyorWithTen = $measure('conveyor');
        $expiredWithTen  = $measure('expired');

        // Sanity check: the lists really did grow, so a flat query count means
        // eager loading, not an empty response.
        $this->getJson('/api/production/conveyor?outletId=' . $outlet->id)->assertJsonCount(10, 'data');
        $this->getJson('/api/production/expired?outletId=' . $outlet->id)->assertJsonCount(10, 'data');

        $this->assertSame($conveyorWithOne, $conveyorWithTen);
        $this->assertSame($expiredWithOne, $expiredWithTen);
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

    public function test_close_day_marks_remaining_items_as_sold(): void
    {
        $outlet    = $this->createOutlet();
        $menu      = $this->createMenu();
        $pending   = $this->createProductionItem($outlet, $menu);
        $alreadyWasted = $this->createProductionItem($outlet, $menu, [
            'final_status' => 'waste',
            'wasted_at'    => now(),
        ]);

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 1);

        $pending->refresh();
        $this->assertSame('sold', $pending->final_status);
        $this->assertNotNull($pending->sold_at);

        // Plate yang sudah dibuang tidak boleh berubah jadi terjual.
        $alreadyWasted->refresh();
        $this->assertSame('waste', $alreadyWasted->final_status);
        $this->assertNull($alreadyWasted->sold_at);
    }

    public function test_close_day_leaves_previous_day_items_untouched(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $stale  = $this->createProductionItem($outlet, $menu, [
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 0);

        // Hari kemarin ditutup oleh autoWasteCarryOver(), bukan oleh close-day.
        $stale->refresh();
        $this->assertNull($stale->final_status);
    }

    public function test_close_day_is_scoped_to_one_outlet(): void
    {
        $outlet      = $this->createOutlet();
        $otherOutlet = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu        = $this->createMenu();

        $mine    = $this->createProductionItem($outlet, $menu);
        $theirs  = $this->createProductionItem($otherOutlet, $menu);

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 1);

        $this->assertSame('sold', $mine->refresh()->final_status);
        $this->assertNull($theirs->refresh()->final_status);
    }

    public function test_close_day_validates_outlet(): void
    {
        $this->postJson('/api/production/close-day', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }
}
