<?php

namespace Tests\Feature\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Models\Outlet;
use App\Models\ProductionStatusCorrection;
use App\Models\SalesHeader;
use App\Models\User;
use App\Models\WasteRecord;
use App\Services\ClosingReport\ClosingReportOutboxService;
use App\Services\Production\ProductionStatusCorrectionService;
use App\Services\Sales\SalesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * Endpoint berbasis id tidak membawa parameter outlet, jadi middleware
 * `outlet.access` tidak punya apa pun untuk diperiksa. Pemeriksaannya ada di
 * service, setelah record (dan outlet pemiliknya) ditemukan.
 *
 * Keputusan: `show` milik outlet lain dijawab 404 — sama persis dengan id yang
 * tidak ada, supaya keberadaan id tidak bocor. Mutasi (`destroy`, `resend`)
 * dijawab 403: user sudah memegang id-nya dari layar yang sah dan perlu tahu
 * kenapa aksinya ditolak.
 */
class OutletScopedRecordAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private Outlet $bdg;
    private Outlet $jkt;

    protected function setUp(): void
    {
        parent::setUp();

        FakeMessagePublisher::swap();

        $this->bdg = $this->createOutlet();
        $this->jkt = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
    }

    /** User non-admin outlet BDG (fixture CreatesUsers memberi outlet ['BDG']). */
    private function bdgUser(string $role = 'operation'): User
    {
        return $this->actingAsRole($role);
    }

    private function sales(Outlet $outlet): SalesHeader
    {
        return SalesHeader::create([
            'outlet_id' => $outlet->id,
            'date'      => now()->toDateString(),
            'status'    => 'draft',
        ]);
    }

    private function closingReport(Outlet $outlet, string $status = 'draft'): ClosingReport
    {
        return ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => now()->toDateString(),
            'status'    => $status,
        ]);
    }

    private function waste(Outlet $outlet): WasteRecord
    {
        $menu = $this->createMenu();
        $item = $this->createProductionItem($outlet, $menu);

        return WasteRecord::create([
            'production_item_id' => $item->id,
            'menu_id'            => $menu->id,
            'outlet_id'          => $outlet->id,
            'plate_color'        => $menu->plate_color_id,
            'quantity'           => 1,
            'reason'             => 'Expired',
            'recorded_at'        => now(),
        ]);
    }

    private function outbox(Outlet $outlet): ClosingReportOutbox
    {
        return ClosingReportOutbox::forceCreate([
            'closing_report_id' => $this->closingReport($outlet, 'submitted')->id,
            'payload'           => ['event' => 'closingreport.submitted'],
            'status'            => ClosingReportOutbox::STATUS_FAILED,
            'attempts'          => 1,
            'next_attempt_at'   => now(),
        ]);
    }

    private function correction(Outlet $outlet): ProductionStatusCorrection
    {
        return ProductionStatusCorrection::create([
            'outlet_id'       => $outlet->id,
            'production_date' => now()->toDateString(),
            'reason'          => 'Salah tekan tombol sold di conveyor',
            'total_to_waste'  => 0,
            'total_to_sold'   => 0,
        ]);
    }

    // ─── GET /sales/{id} ─────────────────────────────────────────────────

    public function test_sales_show_own_outlet_is_ok(): void
    {
        $this->bdgUser();

        $this->getJson('/api/sales/' . $this->sales($this->bdg)->id)->assertOk();
    }

    public function test_sales_show_of_another_outlet_is_404(): void
    {
        $this->bdgUser();

        $this->getJson('/api/sales/' . $this->sales($this->jkt)->id)->assertStatus(404);
    }

    public function test_sales_show_admin_sees_any_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/sales/' . $this->sales($this->jkt)->id)->assertOk();
    }

    // ─── DELETE /sales/{id} (admin saja di route) ────────────────────────

    public function test_sales_cancel_admin_may_cancel_any_outlet(): void
    {
        $this->actingAsRole('admin');
        $sales = $this->sales($this->jkt);

        $this->deleteJson('/api/sales/' . $sales->id, ['reason' => 'Ada piring salah status, perlu dikoreksi'])
            ->assertOk();
    }

    public function test_sales_cancel_service_refuses_another_outlet(): void
    {
        // Route-nya `role:admin`, jadi non-admin tidak pernah sampai lewat HTTP.
        // Yang diuji di sini lapis kedua: service tetap menolak.
        $user  = $this->bdgUser();
        $sales = $this->sales($this->jkt);

        try {
            app(SalesService::class)->cancelDraft($sales->id, 'Ada piring salah status', $user);
            $this->fail('cancelDraft() seharusnya menolak outlet lain');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->render(request())->getStatusCode());
        }

        $this->assertNotSoftDeleted('sales_headers', ['id' => $sales->id]);
    }

    // ─── GET /closing-reports/{id} ───────────────────────────────────────

    public function test_closing_report_show_own_outlet_is_ok(): void
    {
        $this->bdgUser();

        $this->getJson('/api/closing-reports/' . $this->closingReport($this->bdg)->id)->assertOk();
    }

    public function test_closing_report_show_of_another_outlet_is_404(): void
    {
        $this->bdgUser();

        $this->getJson('/api/closing-reports/' . $this->closingReport($this->jkt)->id)->assertStatus(404);
    }

    public function test_closing_report_show_admin_sees_any_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/closing-reports/' . $this->closingReport($this->jkt)->id)->assertOk();
    }

    // ─── DELETE /closing-reports/{id} ────────────────────────────────────

    public function test_manager_may_delete_own_outlet_draft(): void
    {
        $this->bdgUser('manager');
        $report = $this->closingReport($this->bdg);

        $this->deleteJson('/api/closing-reports/' . $report->id)->assertOk();

        $this->assertSoftDeleted('closing_reports', ['id' => $report->id]);
    }

    public function test_manager_may_not_delete_another_outlet_draft(): void
    {
        $this->bdgUser('manager');
        $report = $this->closingReport($this->jkt);

        $this->deleteJson('/api/closing-reports/' . $report->id)
            ->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Outlet ini tidak termasuk akses Anda');

        $this->assertDatabaseHas('closing_reports', ['id' => $report->id, 'deleted_at' => null]);
    }

    public function test_admin_may_delete_any_outlet_draft(): void
    {
        $this->actingAsRole('admin');
        $report = $this->closingReport($this->jkt);

        $this->deleteJson('/api/closing-reports/' . $report->id)->assertOk();
    }

    // ─── GET /waste/{waste} ──────────────────────────────────────────────

    public function test_waste_show_own_outlet_is_ok(): void
    {
        $this->bdgUser();

        $this->getJson('/api/waste/' . $this->waste($this->bdg)->id)->assertOk();
    }

    public function test_waste_show_of_another_outlet_is_404(): void
    {
        $this->bdgUser();

        $this->getJson('/api/waste/' . $this->waste($this->jkt)->id)->assertStatus(404);
    }

    public function test_waste_show_admin_sees_any_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/waste/' . $this->waste($this->jkt)->id)->assertOk();
    }

    // ─── POST /closing-reports/outbox/{id}/resend (admin saja) ───────────

    public function test_outbox_resend_admin_may_resend_any_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/closing-reports/outbox/' . $this->outbox($this->jkt)->id . '/resend')
            ->assertOk();
    }

    public function test_outbox_resend_service_refuses_another_outlet(): void
    {
        $user   = $this->bdgUser();
        $outbox = $this->outbox($this->jkt);

        $this->expectException(BusinessRuleException::class);

        app(ClosingReportOutboxService::class)->resendFor($user, $outbox->id);
    }

    // ─── GET /production/corrections/{id} (admin saja) ───────────────────

    public function test_correction_show_admin_sees_any_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/production/corrections/' . $this->correction($this->jkt)->id)->assertOk();
    }

    public function test_correction_show_service_hides_another_outlet(): void
    {
        $user       = $this->bdgUser();
        $correction = $this->correction($this->jkt);

        $this->expectException(ModelNotFoundException::class);

        app(ProductionStatusCorrectionService::class)->showFor($user, $correction->id);
    }
}
