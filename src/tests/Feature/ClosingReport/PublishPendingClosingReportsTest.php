<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Services\ClosingReport\ClosingReportOutboxService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * `closing-report:publish-pending` — retry pesan closing report yang belum
 * sampai ke BI (broker mati saat submit, atau queue BI belum di-bind).
 */
class PublishPendingClosingReportsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private FakeMessagePublisher $publisher;

    private int $outletSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 15:00:00');
        config(['rabbitmq.closing_report.max_attempts' => 5]);
        $this->publisher = FakeMessagePublisher::swap();
    }

    private function outbox(array $overrides = []): ClosingReportOutbox
    {
        $this->outletSeq++;
        $outlet = $this->createOutlet(['code' => "O{$this->outletSeq}", 'name' => "Outlet {$this->outletSeq}"]);
        $report = ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => '2026-10-02',
            'status'    => 'submitted',
        ]);

        return ClosingReportOutbox::forceCreate(array_merge([
            'closing_report_id' => $report->id,
            'payload'           => ['event' => 'closingreport.submitted', 'messageId' => $report->id],
            'status'            => ClosingReportOutbox::STATUS_FAILED,
            'attempts'          => 1,
            'next_attempt_at'   => now()->subMinute(),
        ], $overrides));
    }

    public function test_it_publishes_rows_that_are_due(): void
    {
        $a = $this->outbox();
        $b = $this->outbox(['status' => ClosingReportOutbox::STATUS_PENDING, 'attempts' => 0]);

        $this->artisan('closing-report:publish-pending')
            ->expectsOutputToContain('2 terkirim, 0 gagal')
            ->assertSuccessful();

        $this->publisher->assertPublishedCount(2);
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $a->fresh()->status);
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $b->fresh()->status);
    }

    public function test_it_leaves_rows_that_are_not_due_or_already_published(): void
    {
        $this->outbox(['next_attempt_at' => now()->addMinutes(10)]);
        $this->outbox(['status' => ClosingReportOutbox::STATUS_PUBLISHED, 'published_at' => now(), 'next_attempt_at' => null]);

        $this->artisan('closing-report:publish-pending')
            ->expectsOutputToContain('Tidak ada pesan closing report yang perlu dikirim')
            ->assertSuccessful();

        $this->publisher->assertNothingPublished();
    }

    public function test_a_failed_retry_is_rescheduled_and_reported(): void
    {
        $this->publisher->failWith('Connection refused');
        $row = $this->outbox(['attempts' => 2]);

        $this->artisan('closing-report:publish-pending')
            ->expectsOutputToContain('0 terkirim, 1 gagal')
            ->assertFailed();

        $row = $row->fresh();
        $this->assertSame(ClosingReportOutbox::STATUS_FAILED, $row->status);
        $this->assertSame(3, $row->attempts);
        // percobaan ke-3 → jeda 4 menit
        $this->assertTrue($row->next_attempt_at->equalTo(now()->addMinutes(4)));
    }

    public function test_it_stops_retrying_after_max_attempts_and_says_so(): void
    {
        $exhausted = $this->outbox(['attempts' => 5]);

        $this->artisan('closing-report:publish-pending')
            ->expectsOutputToContain('1 pesan berhenti dicoba')
            ->assertSuccessful();

        $this->publisher->assertNothingPublished();
        $this->assertSame(5, $exhausted->fresh()->attempts);
    }

    public function test_id_option_forces_one_row_even_when_exhausted_or_not_due(): void
    {
        $target = $this->outbox(['attempts' => 5, 'next_attempt_at' => now()->addHour()]);
        $this->outbox(); // due, tapi bukan yang diminta

        $this->artisan('closing-report:publish-pending', ['--id' => $target->id])
            ->assertSuccessful();

        $this->publisher->assertPublishedCount(1);
        $this->assertSame($target->closing_report_id, $this->publisher->last()['messageId']);
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $target->fresh()->status);
    }

    public function test_id_option_does_not_resend_a_published_row(): void
    {
        $row = $this->outbox(['status' => ClosingReportOutbox::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->artisan('closing-report:publish-pending', ['--id' => $row->id])
            ->expectsOutputToContain('sudah terkirim')
            ->assertSuccessful();

        $this->publisher->assertNothingPublished();
    }

    public function test_rebuild_rebuilds_the_payload_and_resends_a_published_row(): void
    {
        // Pesan dari builder lama: terkirim, tapi ditolak BI karena items kosong.
        $row = $this->outbox([
            'status'       => ClosingReportOutbox::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'payload'      => ['event' => 'closingreport.submitted', 'data' => ['items' => []]],
        ]);
        $report = $row->closingReport;
        $menu = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->createProductionItem($report->outlet, $menu, [
            'produced_at' => '2026-10-02 03:00:00', 'final_status' => 'sold', 'sold_at' => '2026-10-02 03:00:00',
        ]);

        $this->artisan('closing-report:publish-pending', ['--id' => $row->id, '--rebuild' => true])
            ->expectsOutputToContain('items 0 → 1')
            ->assertSuccessful();

        $this->publisher->assertPublishedCount(1);
        $payload = $this->publisher->last()['payload'];
        $this->assertSame($report->id, $payload['messageId']);
        $this->assertSame('Salmon Nigiri', $payload['data']['items'][0]['menuName']);
        $this->assertSame(1, $payload['data']['items'][0]['sold']);

        $row = $row->fresh();
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $row->status);
        $this->assertCount(1, $row->payload['data']['items']);
    }

    public function test_rebuild_dry_run_changes_nothing(): void
    {
        $row = $this->outbox(['status' => ClosingReportOutbox::STATUS_PUBLISHED, 'published_at' => now()]);
        $payload = $row->payload;

        $this->artisan('closing-report:publish-pending', ['--id' => $row->id, '--rebuild' => true, '--dry-run' => true])
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->publisher->assertNothingPublished();
        $this->assertSame($payload, $row->fresh()->payload);
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $row->fresh()->status);
    }

    public function test_rebuild_refuses_a_report_without_menus(): void
    {
        $row = $this->outbox(['status' => ClosingReportOutbox::STATUS_PUBLISHED, 'published_at' => now()]);
        $payload = $row->payload;

        $this->artisan('closing-report:publish-pending', ['--id' => $row->id, '--rebuild' => true])
            ->expectsOutputToContain('tidak punya menu')
            ->assertFailed();

        $this->publisher->assertNothingPublished();
        $this->assertSame($payload, $row->fresh()->payload);
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $row->fresh()->status);
    }

    public function test_rebuild_requires_an_id(): void
    {
        $this->outbox();

        $this->artisan('closing-report:publish-pending', ['--rebuild' => true])
            ->assertFailed();

        $this->publisher->assertNothingPublished();
    }

    public function test_id_option_rejects_a_malformed_id(): void
    {
        $this->artisan('closing-report:publish-pending', ['--id' => 'abc'])
            ->assertFailed();
    }

    public function test_dry_run_lists_without_sending(): void
    {
        $row = $this->outbox(['last_error' => 'Connection refused']);

        $this->artisan('closing-report:publish-pending', ['--dry-run' => true])
            ->expectsOutputToContain($row->id)
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->publisher->assertNothingPublished();
        $this->assertSame(1, $row->fresh()->attempts);
    }

    public function test_limit_caps_one_run_oldest_first(): void
    {
        $oldest = $this->outbox(['created_at' => now()->subHours(2)]);
        $this->outbox(['created_at' => now()->subHour()]);
        $this->outbox(['created_at' => now()]);

        $this->artisan('closing-report:publish-pending', ['--limit' => 1])->assertSuccessful();

        $this->publisher->assertPublishedCount(1);
        $this->assertSame($oldest->closing_report_id, $this->publisher->last()['messageId']);
    }

    public function test_a_freshly_queued_row_is_left_to_the_immediate_publish_first(): void
    {
        // Antara commit submit dan publish langsung, scheduler tidak boleh
        // menyerobot baris yang sama — itu mengirim pesan dua kali.
        $outlet = $this->createOutlet();
        $report = ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => '2026-10-02',
            'status'    => 'submitted',
        ]);
        $this->createProductionItem($outlet, $this->createMenu(), ['produced_at' => '2026-10-02 03:00:00']);
        app(ClosingReportOutboxService::class)->enqueue($report);

        $this->artisan('closing-report:publish-pending')->assertSuccessful();
        $this->publisher->assertNothingPublished();

        // Kalau publish langsung tidak pernah selesai (proses mati), scheduler
        // mengambil alih setelah masa tenggang.
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('closing-report:publish-pending')->assertSuccessful();
        $this->publisher->assertPublishedCount(1);
    }

    public function test_it_is_scheduled_every_minute_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'closing-report:publish-pending'));

        $this->assertCount(1, $events, 'closing-report:publish-pending harus terdaftar di scheduler.');
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_max_attempts_default_covers_about_a_day(): void
    {
        config(['rabbitmq.closing_report.max_attempts' => null]);
        $this->refreshApplication();

        $max = (int) config('rabbitmq.closing_report.max_attempts');
        $service = app(ClosingReportOutboxService::class);
        $totalMinutes = array_sum(array_map(fn ($a) => $service->retryDelayMinutes($a), range(1, $max)));

        $this->assertGreaterThanOrEqual(24 * 60, $totalMinutes);
    }
}
