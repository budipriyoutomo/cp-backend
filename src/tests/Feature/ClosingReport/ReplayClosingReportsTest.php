<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Models\Outlet;
use App\Models\PlateColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * `closing-report:replay` — kirim ulang closing report lampau dalam versi
 * payload saat ini dengan `messageId` baru `<closingReportId>.v<versi>`.
 *
 * Kontraknya dari BI (docs/jawaban-bi-closing-report-v3.md): dedup BI tidak
 * berubah, laporan dikunci pada `closingReportId`, jadi `messageId` baru
 * menjadi revisi aktif. `messageId` harus tetap — menjalankan ulang tidak
 * boleh menghasilkan revisi kedua.
 */
class ReplayClosingReportsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private FakeMessagePublisher $publisher;

    private Outlet $outlet;

    private PlateColors $plateColor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 10:00:00');
        config([
            'rabbitmq.closing_report.payload_version' => '3', // dari .env selalu string
            'rabbitmq.closing_report.max_attempts'    => 5,
        ]);
        $this->publisher = FakeMessagePublisher::swap();
        $this->outlet = $this->createOutlet(['code' => 'BDG']);
        $this->plateColor = $this->createPlateColor();
    }

    /**
     * Laporan submitted yang dulu terkirim sebagai v1 (baris outbox submit,
     * `messageId` = id laporan).
     */
    private function report(string $date, ?Outlet $outlet = null, bool $withMenu = true, string $status = 'submitted'): ClosingReport
    {
        $outlet ??= $this->outlet;

        $report = ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => $date,
            'status'    => $status,
        ]);

        if ($withMenu) {
            $menu = $this->createMenu($this->plateColor, ['menuname' => "Salmon {$date} {$outlet->code}"]);
            $this->createProductionItem($outlet, $menu, [
                'produced_at'  => "{$date} 03:00:00",
                'final_status' => 'sold',
                'sold_at'      => "{$date} 05:00:00",
            ]);
        }

        ClosingReportOutbox::forceCreate([
            'closing_report_id' => $report->id,
            'message_id'        => $report->id,
            'payload'           => ['event' => 'closingreport.submitted', 'version' => 1, 'messageId' => $report->id, 'data' => ['items' => []]],
            'status'            => ClosingReportOutbox::STATUS_PUBLISHED,
            'attempts'          => 1,
            'published_at'      => "{$date} 15:00:00",
        ]);

        return $report;
    }

    /** @return list<string> */
    private function publishedMessageIds(): array
    {
        return (fn () => array_column($this->published, 'messageId'))->call($this->publisher);
    }

    public function test_it_sends_v3_with_a_new_message_id_and_keeps_the_submit_message(): void
    {
        $report = $this->report('2026-10-01');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])
            ->expectsOutputToContain("{$report->id}.v3  items 1  terkirim")
            ->expectsOutputToContain('1 terkirim')
            ->assertSuccessful();

        $sent = $this->publisher->last();
        $this->assertSame("{$report->id}.v3", $sent['messageId']);
        $this->assertSame("{$report->id}.v3", $sent['payload']['messageId']);
        $this->assertSame($report->id, $sent['payload']['data']['closingReportId']);
        $this->assertSame(3, $sent['payload']['version']);
        $this->assertSame(15000.0, (float) $sent['payload']['data']['items'][0]['unitPrice']);
        $this->assertSame(now()->toIso8601String(), $sent['payload']['sentAt']);

        // Pesan submit tidak disentuh, dan tetap yang dibaca layar Closing Report.
        $submit = $report->fresh()->outbox;
        $this->assertSame((string) $report->id, $submit->message_id);
        $this->assertSame(1, $submit->payload['version']);
        $this->assertSame(2, $report->outboxMessages()->count());
    }

    public function test_it_covers_the_date_range_inclusive_and_skips_drafts(): void
    {
        $before = $this->report('2026-09-30');
        $first = $this->report('2026-10-01');
        $last = $this->report('2026-10-31');
        $after = $this->report('2026-11-01');
        $draft = $this->report('2026-10-15', status: 'draft');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--to' => '2026-10-31'])
            ->assertSuccessful();

        $this->assertSame(["{$first->id}.v3", "{$last->id}.v3"], $this->publishedMessageIds());
    }

    public function test_to_defaults_to_today(): void
    {
        $report = $this->report('2026-11-23');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])->assertSuccessful();

        $this->assertSame(["{$report->id}.v3"], $this->publishedMessageIds());
    }

    public function test_outlet_option_limits_to_one_outlet(): void
    {
        $other = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $mine = $this->report('2026-10-01');
        $this->report('2026-10-01', $other);

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--outlet' => 'BDG'])
            ->assertSuccessful();

        $this->assertSame(["{$mine->id}.v3"], $this->publishedMessageIds());
    }

    public function test_report_option_sends_one_sample(): void
    {
        $sample = $this->report('2026-10-01');
        $this->report('2026-10-02');

        $this->artisan('closing-report:replay', ['--report' => $sample->id])->assertSuccessful();

        $this->assertSame(["{$sample->id}.v3"], $this->publishedMessageIds());
    }

    public function test_running_it_twice_does_not_create_a_second_revision(): void
    {
        $report = $this->report('2026-10-01');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])->assertSuccessful();
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])
            ->expectsOutputToContain("{$report->id}.v3  items 1  sudah terkirim")
            ->expectsOutputToContain('Selesai: 0 terkirim, 1 sudah terkirim sebelumnya')
            ->assertSuccessful();

        $this->publisher->assertPublishedCount(1);
        $this->assertSame(1, ClosingReportOutbox::where('message_id', "{$report->id}.v3")->count());
    }

    public function test_a_report_without_menus_is_skipped_and_the_rest_continue(): void
    {
        $empty = $this->report('2026-10-01', withMenu: false);
        $full = $this->report('2026-10-02');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])
            ->expectsOutputToContain('dilewati: tanpa menu')
            ->expectsOutputToContain('1 terkirim, 0 sudah terkirim sebelumnya, 1 dilewati')
            ->assertSuccessful();

        $this->assertSame(["{$full->id}.v3"], $this->publishedMessageIds());
        $this->assertFalse(ClosingReportOutbox::where('message_id', "{$empty->id}.v3")->exists());
    }

    public function test_a_broker_failure_stops_the_run_and_a_rerun_finishes_it(): void
    {
        $first = $this->report('2026-10-01');
        $second = $this->report('2026-10-02');

        $this->publisher->failWith('Connection refused');
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])
            ->expectsOutputToContain('GAGAL: Connection refused')
            ->expectsOutputToContain('1 laporan belum diproses')
            ->assertFailed();

        // Yang gagal tetap di outbox — scheduler akan mencobanya lagi.
        $failed = ClosingReportOutbox::where('message_id', "{$first->id}.v3")->firstOrFail();
        $this->assertSame(ClosingReportOutbox::STATUS_FAILED, $failed->status);
        $this->assertFalse(ClosingReportOutbox::where('message_id', "{$second->id}.v3")->exists());

        $this->publisher->failWith(null);
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])
            ->expectsOutputToContain('2 terkirim')
            ->assertSuccessful();

        $this->assertSame(["{$first->id}.v3", "{$second->id}.v3"], $this->publishedMessageIds());
    }

    public function test_a_failed_replay_is_retried_by_the_scheduler_with_its_own_message_id(): void
    {
        $report = $this->report('2026-10-01');

        $this->publisher->failWith('Connection refused');
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])->assertFailed();

        $this->publisher->failWith(null);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('closing-report:publish-pending')->assertSuccessful();

        $this->assertSame(["{$report->id}.v3"], $this->publishedMessageIds());
        $this->assertSame("{$report->id}.v3", $this->publisher->last()['payload']['messageId']);
    }

    public function test_dry_run_sends_and_writes_nothing(): void
    {
        $report = $this->report('2026-10-01');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--dry-run' => true])
            ->expectsOutputToContain("{$report->id}.v3  items 1  akan dikirim")
            ->expectsOutputToContain('Dry run: 1 akan dikirim')
            ->assertSuccessful();

        $this->publisher->assertNothingPublished();
        $this->assertSame(1, ClosingReportOutbox::count());
    }

    public function test_it_refuses_while_the_payload_version_is_still_v1(): void
    {
        config(['rabbitmq.closing_report.payload_version' => '1']);
        $this->report('2026-10-01');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])
            ->expectsOutputToContain('RABBITMQ_CLOSING_REPORT_PAYLOAD_VERSION=3')
            ->assertFailed();

        $this->publisher->assertNothingPublished();
    }

    public function test_it_needs_from_or_report_and_valid_values(): void
    {
        $this->report('2026-10-01');

        $this->artisan('closing-report:replay')->assertFailed();
        $this->artisan('closing-report:replay', ['--from' => '2026-13-01'])->assertFailed();
        $this->artisan('closing-report:replay', ['--from' => '2026-10-05', '--to' => '2026-10-01'])->assertFailed();
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--outlet' => 'XXX'])->assertFailed();
        $this->artisan('closing-report:replay', ['--report' => 'abc'])->assertFailed();

        $this->publisher->assertNothingPublished();
    }

    public function test_the_submit_message_is_still_deduplicated_by_its_message_id(): void
    {
        $report = $this->report('2026-10-01');
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])->assertSuccessful();

        // Re-submit yang diputar ulang tidak boleh mengantre pesan submit kedua.
        $again = app(\App\Services\ClosingReport\ClosingReportOutboxService::class)->enqueue($report);

        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame((string) $report->id, $again->message_id);
        $this->assertSame(2, ClosingReportOutbox::count());
    }

    public function test_rebuilding_a_replay_row_keeps_its_own_message_id(): void
    {
        $report = $this->report('2026-10-01');
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])->assertSuccessful();
        $replay = ClosingReportOutbox::where('message_id', "{$report->id}.v3")->firstOrFail();

        $this->artisan('closing-report:publish-pending', ['--id' => $replay->id, '--rebuild' => true])
            ->assertSuccessful();

        $this->assertSame("{$report->id}.v3", $this->publisher->last()['messageId']);
        $this->assertSame("{$report->id}.v3", $this->publisher->last()['payload']['messageId']);
    }

    /**
     * Kiriman ulang `.v3` sudah tersimpan di BI, lalu payload v3 bertambah
     * (`plateColor`). BI membuang `messageId` yang sama, jadi revisi baru
     * butuh akhiran baru — tetap tanpa waktu.
     */
    public function test_revision_option_resends_an_already_replayed_report_with_a_new_message_id(): void
    {
        $report = $this->report('2026-10-01');
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01'])->assertSuccessful();

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--revision' => '2'])
            ->expectsOutputToContain('revisi 2')
            ->expectsOutputToContain("{$report->id}.v3.r2  items 1  terkirim")
            ->assertSuccessful();

        $sent = $this->publisher->last();
        $this->assertSame("{$report->id}.v3.r2", $sent['messageId']);
        $this->assertSame("{$report->id}.v3.r2", $sent['payload']['messageId']);
        $this->assertSame($report->id, $sent['payload']['data']['closingReportId']);
        $this->assertArrayHasKey('plateColor', $sent['payload']['data']['items'][0]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $sent['messageId']); // id korelasi BI

        // Menjalankan revisi yang sama lagi tidak membuat revisi ketiga.
        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--revision' => '2'])
            ->expectsOutputToContain('1 sudah terkirim sebelumnya')
            ->assertSuccessful();

        $this->assertSame(["{$report->id}.v3", "{$report->id}.v3.r2"], $this->publishedMessageIds());
        $this->assertSame(3, $report->outboxMessages()->count());
        $this->assertSame((string) $report->id, $report->fresh()->outbox->message_id);
    }

    public function test_revision_one_is_the_plain_replay_message_id(): void
    {
        $report = $this->report('2026-10-01');

        $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--revision' => '1'])->assertSuccessful();

        $this->assertSame(["{$report->id}.v3"], $this->publishedMessageIds());
    }

    public function test_revision_must_be_a_positive_integer(): void
    {
        $this->report('2026-10-01');

        foreach (['0', '-1', 'abc', '2.5'] as $revision) {
            $this->artisan('closing-report:replay', ['--from' => '2026-10-01', '--revision' => $revision])
                ->expectsOutputToContain('--revision harus bilangan bulat')
                ->assertFailed();
        }

        $this->publisher->assertNothingPublished();
    }
}
