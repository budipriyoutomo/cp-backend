<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * Layar admin "Kirim Ulang BI" (`/admin/bi-sync`).
 *
 * Retry otomatis menangani broker yang mati sebentar. Layar ini untuk sisanya:
 * laporan yang berhenti dicoba setelah batas percobaan, atau yang perlu
 * dikirim sekarang tanpa menunggu jadwal. Hanya admin — sama seperti import
 * backdate, ini alat perbaikan, bukan alur harian operator.
 *
 * Laporan yang sudah terkirim sengaja tidak bisa dikirim ulang dari sini.
 */
class ClosingReportOutboxApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private FakeMessagePublisher $publisher;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 15:00:00');
        config(['rabbitmq.closing_report.max_attempts' => 5]);
        $this->publisher = FakeMessagePublisher::swap();
        $this->outlet = $this->createOutlet();
        $this->actingAsRole('admin');
    }

    private function outbox(?Outlet $outlet = null, string $date = '2026-10-01', array $overrides = []): ClosingReportOutbox
    {
        $report = ClosingReport::create([
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'date'      => $date,
            'status'    => 'submitted',
        ]);

        return ClosingReportOutbox::forceCreate(array_merge([
            'closing_report_id' => $report->id,
            'payload'           => ['event' => 'closingreport.submitted', 'messageId' => $report->id],
            'status'            => ClosingReportOutbox::STATUS_FAILED,
            'attempts'          => 2,
            'last_error'        => 'Connection refused',
            'next_attempt_at'   => now()->addMinutes(4),
        ], $overrides));
    }

    private function published(?Outlet $outlet = null, string $date = '2026-09-30'): ClosingReportOutbox
    {
        return $this->outbox($outlet, $date, [
            'status'          => ClosingReportOutbox::STATUS_PUBLISHED,
            'attempts'        => 1,
            'last_error'      => null,
            'next_attempt_at' => null,
            'published_at'    => now()->subDay(),
        ]);
    }

    private function list(array $query = [])
    {
        return $this->getJson('/api/closing-reports/outbox?' . http_build_query(
            array_merge(['outletId' => $this->outlet->id], $query)
        ));
    }

    private function userWith(string $role, array $modules): User
    {
        return User::create([
            'name'       => ucfirst($role),
            'email'      => $role . '+' . uniqid() . '@example.com',
            'password'   => 'secret123',
            'role'       => $role,
            'departemen' => 'Operation',
            'outlet'     => ['BDG'],
            'module_app' => $modules,
        ]);
    }

    // ─── daftar ──────────────────────────────────────────────────────────

    public function test_list_shows_unsent_rows_of_the_chosen_outlet_by_default(): void
    {
        $failed = $this->outbox();
        $pending = $this->outbox(null, '2026-10-02', ['status' => ClosingReportOutbox::STATUS_PENDING, 'attempts' => 0]);
        $this->published();
        $this->outbox($this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']));

        $response = $this->list()->assertOk();

        // Terbaru dulu.
        $this->assertSame([$pending->id, $failed->id], array_column($response->json('data'), 'id'));
    }

    public function test_list_row_carries_what_an_admin_needs_to_decide(): void
    {
        $row = $this->outbox();

        $this->list()
            ->assertOk()
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.closingReportId', $row->closing_report_id)
            ->assertJsonPath('data.0.date', '2026-10-01')
            ->assertJsonPath('data.0.outletCode', 'BDG')
            ->assertJsonPath('data.0.outletName', 'Bandung')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.attempts', 2)
            ->assertJsonPath('data.0.maxAttempts', 5)
            ->assertJsonPath('data.0.exhausted', false)
            ->assertJsonPath('data.0.lastError', 'Connection refused')
            ->assertJsonPath('data.0.nextAttemptAt', now()->addMinutes(4)->toIso8601String())
            ->assertJsonPath('data.0.publishedAt', null);
    }

    public function test_list_flags_rows_that_stopped_retrying(): void
    {
        $this->outbox(null, '2026-10-01', ['attempts' => 5]);

        $this->list()->assertJsonPath('data.0.exhausted', true);
    }

    public function test_list_can_show_published_or_everything(): void
    {
        $published = $this->published();
        $this->outbox();

        $this->assertSame([$published->id], array_column($this->list(['status' => 'published'])->json('data'), 'id'));
        $this->assertCount(2, $this->list(['status' => 'all'])->json('data'));
    }

    public function test_list_filters_by_report_date_range(): void
    {
        $this->outbox(null, '2026-09-20');
        $inRange = $this->outbox(null, '2026-09-25');
        $this->outbox(null, '2026-10-01');

        $ids = array_column($this->list(['startDate' => '2026-09-24', 'endDate' => '2026-09-26'])->json('data'), 'id');

        $this->assertSame([$inRange->id], $ids);
    }

    public function test_list_requires_an_outlet(): void
    {
        $this->getJson('/api/closing-reports/outbox')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_list_rejects_an_unknown_status(): void
    {
        $this->list(['status' => 'deleted'])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    // ─── kirim ulang ─────────────────────────────────────────────────────

    public function test_resend_publishes_now_and_returns_the_updated_row(): void
    {
        $row = $this->outbox();

        $this->postJson("/api/closing-reports/outbox/{$row->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.lastError', null);

        $this->publisher->assertPublishedCount(1);
        $this->assertSame($row->closing_report_id, $this->publisher->last()['messageId']);
    }

    public function test_resend_ignores_the_schedule_and_the_attempt_limit(): void
    {
        $row = $this->outbox(null, '2026-10-01', ['attempts' => 5, 'next_attempt_at' => now()->addHour()]);

        $this->postJson("/api/closing-reports/outbox/{$row->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_a_failed_resend_is_reported_in_the_row_not_as_an_http_error(): void
    {
        // Request-nya berhasil; yang gagal brokernya. Admin perlu melihat
        // sebabnya, dan baris tetap terjadwal untuk retry otomatis.
        $this->publisher->failWith('NO_ROUTE');
        $row = $this->outbox();

        $this->postJson("/api/closing-reports/outbox/{$row->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.attempts', 3)
            ->assertJsonPath('data.lastError', 'NO_ROUTE');
    }

    public function test_resend_refuses_a_row_that_was_already_published(): void
    {
        $row = $this->published();

        $this->postJson("/api/closing-reports/outbox/{$row->id}/resend")
            ->assertStatus(409)
            ->assertJsonPath('status', false);

        $this->publisher->assertNothingPublished();
    }

    public function test_a_replayed_resend_request_does_not_publish_twice(): void
    {
        $row = $this->outbox();

        $this->withHeaders(['X-Client-Request-Id' => 'admin-resend-1'])
            ->postJson("/api/closing-reports/outbox/{$row->id}/resend")->assertOk();
        $this->withHeaders(['X-Client-Request-Id' => 'admin-resend-1'])
            ->postJson("/api/closing-reports/outbox/{$row->id}/resend")->assertOk();

        $this->publisher->assertPublishedCount(1);
    }

    public function test_resend_of_an_unknown_or_malformed_id_is_404(): void
    {
        $this->postJson('/api/closing-reports/outbox/11111111-1111-1111-1111-111111111111/resend')->assertNotFound();
        $this->postJson('/api/closing-reports/outbox/abc/resend')->assertNotFound();
    }

    // ─── akses ───────────────────────────────────────────────────────────

    public function test_operation_staff_cannot_use_it(): void
    {
        $row = $this->outbox();
        $this->actingAs($this->userWith('operation', ['operation', 'report']), 'api');

        $this->list()->assertForbidden();
        $this->postJson("/api/closing-reports/outbox/{$row->id}/resend")->assertForbidden();
        $this->publisher->assertNothingPublished();
    }

    public function test_an_admin_without_the_admin_module_cannot_use_it(): void
    {
        $this->actingAs($this->userWith('admin', ['operation']), 'api');

        $this->list()->assertForbidden();
    }
}
