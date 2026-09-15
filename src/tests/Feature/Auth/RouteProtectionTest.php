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

    /**
     * Segmen `{id}` dibatasi bentuk uuid di routes/api.php — id yang bentuknya
     * salah tidak pernah cocok dengan route-nya, jadi jawabannya 404 dan gerbang
     * auth tidak pernah dilewati. Placeholder di bawah karena itu harus
     * berbentuk uuid: yang diuji di sini gerbangnya, bukan pencocokan route.
     */
    private const SOME_UUID = '00000000-0000-4000-8000-000000000000';

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
            'GET /production/conveyor-grouped' => ['get', '/api/production/conveyor-grouped'],
            'POST /production/produce'       => ['post', '/api/production/produce'],
            'POST /production/mark-sold'     => ['post', '/api/production/mark-sold'],
            'POST /production/mark-waste'    => ['post', '/api/production/mark-waste'],
            'POST /production/close-day'     => ['post', '/api/production/close-day'],
            'GET /production/expired-grouped' => ['get', '/api/production/expired-grouped'],
            'POST /production/expired/bulk' => ['post', '/api/production/expired/bulk'],
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
            'GET /sales/{id}'                => ['get', '/api/sales/' . self::SOME_UUID],

            // closing reports
            'GET /closing-reports'           => ['get', '/api/closing-reports'],
            'GET /closing-reports/data'      => ['get', '/api/closing-reports/data'],
            'POST /closing-reports/submit'   => ['post', '/api/closing-reports/submit'],
            'POST /closing-reports/upload'   => ['post', '/api/closing-reports/upload-photos'],
            'GET /closing-reports/{id}'      => ['get', '/api/closing-reports/' . self::SOME_UUID],
            'DELETE /closing-reports/{id}'   => ['delete', '/api/closing-reports/' . self::SOME_UUID],

            // production — import backdate (prefix production, modul admin)
            'POST /production/import-backdate' => ['post', '/api/production/import-backdate'],
            'POST /production/import-preview'  => ['post', '/api/production/import-backdate/preview'],

            // waste
            'GET /waste'                     => ['get', '/api/waste'],
            'GET /waste/summary'             => ['get', '/api/waste/summary'],
            'GET /waste/{id}'                => ['get', '/api/waste/' . self::SOME_UUID],
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
        $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)->assertOk();
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
