<?php

namespace Tests\Feature\Auth;

use App\Models\ClosingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Every domain route requires authentication.
 *
 * The /production, /reports, /sales, /closing-reports and /waste groups used to
 * carry no auth middleware at all: anyone who knew a URL could read sales
 * figures for every outlet, invent production data, mark plates sold, and push
 * files to S3. AuthGuard on the frontend only hid the pages.
 *
 * A side effect worth keeping in mind: HasUserstamps fills created_by/updated_by
 * from Auth::id(), which was always null on those endpoints. There was no audit
 * trail either.
 */
class RouteProtectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    public static function guardedRoutes(): array
    {
        return [
            // auth — `register` menetapkan role & module_app dari payload, jadi
            // membiarkannya publik membatalkan semua middleware `role:` di bawah.
            'POST /register'                 => ['post', '/api/register'],

            // users
            'GET /users'                     => ['get', '/api/users'],
            'POST /users'                    => ['post', '/api/users'],
            'PUT /users/{id}'                => ['put', '/api/users/1'],
            'DELETE /users/{id}'             => ['delete', '/api/users/1'],

            // production
            'GET /production/stats'          => ['get', '/api/production/stats'],
            'GET /production/plan'           => ['get', '/api/production/plan'],
            'POST /production/plan'          => ['post', '/api/production/plan'],
            'GET /production/conveyor'       => ['get', '/api/production/conveyor'],
            'POST /production/produce'       => ['post', '/api/production/produce'],
            'POST /production/mark-sold'     => ['post', '/api/production/mark-sold'],
            'POST /production/mark-waste'    => ['post', '/api/production/mark-waste'],
            'POST /production/close-day'     => ['post', '/api/production/close-day'],
            'GET /production/expired'        => ['get', '/api/production/expired'],
            'PUT /production/expired/{id}'   => ['put', '/api/production/expired/some-id'],
            'POST /production/waste'         => ['post', '/api/production/waste'],
            'GET /production/waste'          => ['get', '/api/production/waste'],
            'GET /production/items'          => ['get', '/api/production/items'],
            'POST /production/import-preview' => ['post', '/api/production/import-backdate/preview'],
            'POST /production/import'         => ['post', '/api/production/import-backdate'],

            // reports
            'GET /reports/pos-data'          => ['get', '/api/reports/pos-data'],
            'GET /reports/daily-summary'     => ['get', '/api/reports/daily-summary'],
            'GET /reports/waste-analysis'    => ['get', '/api/reports/waste-analysis'],
            'GET /reports/menu-detail'       => ['get', '/api/reports/production-menu-detail'],

            // sales
            'GET /sales'                     => ['get', '/api/sales'],
            'POST /sales'                    => ['post', '/api/sales'],
            'GET /sales/by-date'             => ['get', '/api/sales/by-date'],
            'GET /sales/{id}'                => ['get', '/api/sales/some-id'],

            // closing reports
            'GET /closing-reports'           => ['get', '/api/closing-reports'],
            'GET /closing-reports/data'      => ['get', '/api/closing-reports/data'],
            'POST /closing-reports/submit'   => ['post', '/api/closing-reports/submit'],
            'POST /closing-reports/upload'   => ['post', '/api/closing-reports/upload-photos'],
            'GET /closing-reports/{id}'      => ['get', '/api/closing-reports/some-id'],
            'DELETE /closing-reports/{id}'   => ['delete', '/api/closing-reports/some-id'],

            // waste
            'GET /waste'                     => ['get', '/api/waste'],
            'GET /waste/summary'             => ['get', '/api/waste/summary'],
            'GET /waste/{id}'                => ['get', '/api/waste/some-id'],
        ];
    }

    /**
     * @dataProvider guardedRoutes
     */
    public function test_guests_are_rejected(string $method, string $uri): void
    {
        $this->json(strtoupper($method), $uri)->assertStatus(401);
    }

    public function test_an_authenticated_user_gets_past_the_guard(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsRole('admin');

        // Not asserting the payload here — only that auth is no longer the blocker.
        $this->getJson('/api/production/conveyor?outletId=' . $outlet->id)->assertOk();
        $this->getJson('/api/waste/summary?outletId=' . $outlet->id . '&date=' . now()->toDateString())
            ->assertOk();
    }

    public function test_deleting_a_closing_report_is_restricted_to_admin_and_manager(): void
    {
        $outlet = $this->createOutlet();

        $report = ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => now()->toDateString(),
            'status'    => 'draft',
        ]);

        $this->actingAsRole('kitchen');
        $this->deleteJson('/api/closing-reports/' . $report->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized access');

        $this->actingAsRole('admin');
        $this->deleteJson('/api/closing-reports/' . $report->id)->assertOk();
    }

    public function test_userstamps_are_recorded_now_that_a_user_is_present(): void
    {
        $user   = $this->actingAsRole('kitchen');
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 1,
            'outletId' => $outlet->id,
        ])->assertOk();

        $this->assertDatabaseHas('production_items', [
            'menu_id'    => $menu->id,
            'created_by' => $user->id,
        ]);
    }
}
