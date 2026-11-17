<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * `GET /closing-reports` dulu hanya menyaring outlet kalau `?outletId=` diisi.
 * Tanpa parameter, middleware `outlet.access` tidak punya apa pun untuk
 * diperiksa dan service mengembalikan laporan semua outlet. Sekarang outlet
 * wajib — admin sekalipun, sama seperti `/closing-reports/outbox`.
 */
class ClosingReportIndexScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private Outlet $bdg;
    private Outlet $jkt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bdg = $this->createOutlet();
        $this->jkt = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);

        foreach ([$this->bdg, $this->jkt] as $outlet) {
            ClosingReport::create([
                'outlet_id' => $outlet->id,
                'date'      => '2026-11-01',
                'status'    => 'submitted',
            ]);
        }

        // Staf modul report outlet BDG (role `report` tidak ada — itu modul).
        $this->actingAsRole('operation');
    }

    public function test_listing_without_an_outlet_is_rejected(): void
    {
        $this->getJson('/api/closing-reports')
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors('outletId', 'errors');
    }

    public function test_listing_another_outlet_is_forbidden(): void
    {
        $this->getJson('/api/closing-reports?outletId=' . $this->jkt->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Outlet ini tidak termasuk akses Anda');
    }

    public function test_listing_own_outlet_returns_only_that_outlet(): void
    {
        $response = $this->getJson('/api/closing-reports?outletId=' . $this->bdg->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([$this->bdg->id], array_map(
            fn ($row) => trim((string) ($row['outletId'] ?? $row['outlet_id'] ?? '')),
            $response->json('data')
        ));
    }

    public function test_admin_must_name_an_outlet_too(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/closing-reports')->assertStatus(422);

        $this->getJson('/api/closing-reports?outletId=' . $this->jkt->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_malformed_or_unknown_outlet_is_422_not_500(): void
    {
        $this->getJson('/api/closing-reports?outletId=abc')->assertStatus(422);
        $this->getJson('/api/closing-reports?outletId=11111111-1111-1111-1111-111111111111')
            ->assertStatus(422);
    }
}
