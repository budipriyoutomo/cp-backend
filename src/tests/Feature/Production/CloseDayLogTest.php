<?php

namespace Tests\Feature\Production;

use App\Models\CloseDayLog;
use App\Models\User;
use App\Services\Production\ProductionItemService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Audit log penutupan massal piring sisa: tombol Tutup Hari
 * (`POST /production/close-day`) dan auto-waste (`autoWasteCarryOver()`).
 * Laporannya dibaca lewat `GET /reports/close-day-logs`.
 */
class CloseDayLogTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const ENDPOINT = '/api/reports/close-day-logs';

    /*
    |--------------------------------------------------------------------------
    | Tutup Hari
    |--------------------------------------------------------------------------
    */

    public function test_close_day_records_who_when_and_how_many(): void
    {
        $user   = $this->actingAsRole('kitchen');
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $this->createProductionItem($outlet, $menu);
        $this->createProductionItem($outlet, $menu);

        $requestId = (string) Str::uuid();

        $this->withHeaders(['X-Client-Request-Id' => $requestId, 'User-Agent' => 'TabletDapur/1.0'])
            ->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 2);

        $log = CloseDayLog::sole();
        $this->assertSame($outlet->id, $log->outlet_id);
        $this->assertSame(CloseDayLog::ACTION_CLOSE_DAY, $log->action);
        $this->assertSame(CloseDayLog::TRIGGER_BUTTON, $log->trigger);
        $this->assertSame(today()->toDateString(), $log->business_date->toDateString());
        $this->assertSame(2, $log->closed_count);
        $this->assertNotNull($log->executed_at);
        $this->assertSame($requestId, $log->client_request_id);
        $this->assertSame('TabletDapur/1.0', $log->user_agent);
        $this->assertNotNull($log->ip_address);
        $this->assertSame((string) $user->id, trim((string) $log->created_by));
    }

    public function test_close_day_with_nothing_to_close_is_still_logged(): void
    {
        $this->actingAsRole('kitchen');
        $outlet = $this->createOutlet();

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 0);

        $this->assertSame(0, CloseDayLog::sole()->closed_count);
    }

    public function test_an_idempotent_replay_does_not_add_a_second_log(): void
    {
        $this->actingAsRole('kitchen');
        $outlet  = $this->createOutlet();
        $headers = ['X-Client-Request-Id' => (string) Str::uuid()];

        $this->withHeaders($headers)
            ->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame(1, CloseDayLog::count());
    }

    public function test_failed_validation_writes_no_log(): void
    {
        $this->actingAsRole('kitchen');

        $this->postJson('/api/production/close-day', [])->assertStatus(422);

        $this->assertSame(0, CloseDayLog::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Auto-waste
    |--------------------------------------------------------------------------
    */

    public function test_auto_waste_logs_one_row_per_outlet_and_production_day(): void
    {
        $outlet = $this->createOutlet();
        $other  = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu   = $this->createMenu();

        $yesterday = now()->subDay()->setTime(12, 0);
        $twoDays   = now()->subDays(2)->setTime(12, 0);

        foreach ([$yesterday, $yesterday, $twoDays] as $at) {
            $this->createProductionItem($outlet, $menu, ['produced_at' => $at, 'expires_at' => $at->copy()->addHour()]);
        }
        $this->createProductionItem($other, $menu, ['produced_at' => $yesterday, 'expires_at' => $yesterday->copy()->addHour()]);
        // Hari ini bukan urusan auto-waste.
        $this->createProductionItem($outlet, $menu);

        $this->artisan('production:close-stale --scheduled')->assertSuccessful();

        $logs = CloseDayLog::orderBy('business_date')->get();
        $this->assertCount(3, $logs);
        $this->assertTrue($logs->every(fn ($l) => $l->action === CloseDayLog::ACTION_AUTO_WASTE));
        $this->assertTrue($logs->every(fn ($l) => $l->trigger === CloseDayLog::TRIGGER_SCHEDULER));

        $mine = $logs->where('outlet_id', $outlet->id)->keyBy(fn ($l) => $l->business_date->toDateString());
        $this->assertSame(2, $mine[$yesterday->toDateString()]->closed_count);
        $this->assertSame(1, $mine[$twoDays->toDateString()]->closed_count);
        $this->assertSame(1, $logs->firstWhere('outlet_id', $other->id)->closed_count);

        // Dijalankan sistem, bukan user.
        $this->assertNull($logs->first()->created_by);
    }

    public function test_manual_artisan_run_is_logged_as_command(): void
    {
        $outlet = $this->createOutlet();
        $at     = now()->subDay();
        $this->createProductionItem($outlet, $this->createMenu(), ['produced_at' => $at, 'expires_at' => $at->copy()->addHour()]);

        $this->artisan('production:close-stale')->assertSuccessful();

        $this->assertSame(CloseDayLog::TRIGGER_COMMAND, CloseDayLog::sole()->trigger);
    }

    public function test_auto_waste_with_nothing_to_close_writes_no_log(): void
    {
        $this->createOutlet();

        $this->artisan('production:close-stale --scheduled')->assertSuccessful();

        $this->assertSame(0, CloseDayLog::count());
    }

    public function test_auto_waste_from_get_data_pos_is_tagged_as_such(): void
    {
        $outlet = $this->createOutlet();
        $at     = now()->subDay();
        $this->createProductionItem($outlet, $this->createMenu(), ['produced_at' => $at, 'expires_at' => $at->copy()->addHour()]);

        app(ProductionItemService::class)->autoWasteCarryOver($outlet->id, CloseDayLog::TRIGGER_GET_DATA_POS);

        $this->assertSame(CloseDayLog::TRIGGER_GET_DATA_POS, CloseDayLog::sole()->trigger);
    }

    public function test_the_midnight_schedule_passes_the_scheduled_flag(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'production:close-stale'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--scheduled', $events->first()->command);
    }

    /*
    |--------------------------------------------------------------------------
    | Laporan
    |--------------------------------------------------------------------------
    */

    public function test_report_lists_one_outlet_newest_first(): void
    {
        $outlet = $this->createOutlet();
        $other  = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);

        $kitchen = $this->actingAsRole('kitchen');
        $this->travelTo(now()->setTime(21, 0));
        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])->assertOk();
        $this->travelTo(now()->setTime(22, 0));
        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])->assertOk();
        $this->postJson('/api/production/close-day', ['outletId' => $other->id]);

        $this->actingAsRole('admin');

        $response = $this->getJson(self::ENDPOINT . '?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.outletCode', 'BDG')
            ->assertJsonPath('data.0.action', 'close_day')
            ->assertJsonPath('data.0.trigger', 'button')
            ->assertJsonPath('data.0.businessDate', today()->toDateString())
            ->assertJsonPath('data.0.executedByName', $kitchen->name)
            ->assertJsonPath('meta.total', 2);

        $this->assertGreaterThan($response->json('data.1.executedAt'), $response->json('data.0.executedAt'));
    }

    public function test_report_can_be_filtered_by_action(): void
    {
        $outlet = $this->createOutlet();
        $at     = now()->subDay();
        $this->createProductionItem($outlet, $this->createMenu(), ['produced_at' => $at, 'expires_at' => $at->copy()->addHour()]);
        $this->artisan('production:close-stale --scheduled')->assertSuccessful();

        $this->actingAsRole('kitchen');
        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])->assertOk();

        $this->actingAsRole('admin');

        $this->getJson(self::ENDPOINT . "?outletId={$outlet->id}&action=auto_waste")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'auto_waste')
            ->assertJsonPath('data.0.trigger', 'scheduler')
            ->assertJsonPath('data.0.executedBy', null)
            ->assertJsonPath('data.0.businessDate', $at->toDateString());

        $this->getJson(self::ENDPOINT . "?outletId={$outlet->id}&action=bogus")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_report_can_be_filtered_by_business_date(): void
    {
        $outlet = $this->createOutlet();

        $this->actingAsRole('kitchen');
        $this->travelTo(now()->subDays(3));
        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])->assertOk();
        $this->travelBack();
        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])->assertOk();

        $this->actingAsRole('admin');
        $today = today()->toDateString();

        $this->getJson(self::ENDPOINT . "?outletId={$outlet->id}&startDate={$today}&endDate={$today}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.businessDate', $today);
    }

    public function test_report_requires_an_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->getJson(self::ENDPOINT)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_report_needs_the_report_or_operation_module(): void
    {
        $outlet = $this->createOutlet();

        $kitchenOnly = User::create([
            'name'       => 'Dapur',
            'email'      => 'dapur+' . uniqid() . '@example.com',
            'password'   => 'secret123',
            'role'       => 'kitchen',
            'departemen' => 'Kitchen',
            'outlet'     => ['BDG'],
            'module_app' => ['app', 'kitchen'],
        ]);

        $this->actingAs($kitchenOnly, 'api')
            ->getJson(self::ENDPOINT . '?outletId=' . $outlet->id)
            ->assertForbidden();
    }
}
