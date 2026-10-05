<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Outbox for the `closingreport.submitted` message to BI.
 *
 * The row is written in the same transaction as the submit, then published
 * after commit. If RabbitMQ is down the submit still succeeds and the row waits
 * here for a retry — so the row itself must carry everything a retry needs:
 * the payload, how many attempts were made, and when the next one is due.
 */
class ClosingReportOutboxTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private const DATE = '2026-10-02';

    private function createReport(array $overrides = []): ClosingReport
    {
        return ClosingReport::create(array_merge([
            'outlet_id' => $overrides['outlet_id'] ?? $this->createOutlet()->id,
            'date'      => self::DATE,
            'status'    => 'submitted',
        ], $overrides));
    }

    private function createOutbox(?ClosingReport $report = null, array $overrides = []): ClosingReportOutbox
    {
        $report ??= $this->createReport();

        return ClosingReportOutbox::create(array_merge([
            'closing_report_id' => $report->id,
            'payload'           => ['event' => 'closingreport.submitted', 'version' => 1],
        ], $overrides));
    }

    public function test_a_new_row_is_pending_with_no_attempts(): void
    {
        $outbox = $this->createOutbox()->fresh();

        $this->assertSame(ClosingReportOutbox::STATUS_PENDING, $outbox->status);
        $this->assertSame(0, $outbox->attempts);
        $this->assertNull($outbox->published_at);
        $this->assertNull($outbox->next_attempt_at);
        $this->assertNull($outbox->last_error);
    }

    public function test_payload_round_trips_as_an_array(): void
    {
        $payload = [
            'event'   => 'closingreport.submitted',
            'version' => 1,
            'data'    => ['items' => [['menuCode' => null, 'sold' => 42]]],
        ];

        $outbox = $this->createOutbox(null, ['payload' => $payload])->fresh();

        $this->assertSame($payload, $outbox->payload);
    }

    public function test_a_closing_report_has_at_most_one_outbox_row(): void
    {
        // A re-submit or a replayed request must never queue a second message.
        $report = $this->createReport();
        $this->createOutbox($report);

        $this->expectException(QueryException::class);

        $this->createOutbox($report);
    }

    public function test_closing_report_exposes_its_outbox_row(): void
    {
        $report = $this->createReport();
        $outbox = $this->createOutbox($report);

        $this->assertTrue($report->outbox->is($outbox));
        $this->assertTrue($outbox->closingReport->is($report));
    }

    public function test_mark_published_records_the_time_and_clears_the_error(): void
    {
        Carbon::setTestNow('2026-10-02 22:15:00');

        $outbox = $this->createOutbox(null, [
            'status'          => ClosingReportOutbox::STATUS_FAILED,
            'attempts'        => 2,
            'last_error'      => 'Connection refused',
            'next_attempt_at' => now()->subMinute(),
        ]);

        $outbox->markPublished();
        $outbox = $outbox->fresh();

        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $outbox->status);
        $this->assertSame(3, $outbox->attempts);
        $this->assertTrue($outbox->published_at->equalTo(now()));
        $this->assertNull($outbox->last_error);
        $this->assertNull($outbox->next_attempt_at);
    }

    public function test_mark_failed_counts_the_attempt_and_schedules_the_next_one(): void
    {
        Carbon::setTestNow('2026-10-02 22:15:00');

        $outbox = $this->createOutbox();
        $retryAt = now()->addMinutes(5);

        $outbox->markFailed('Connection refused', $retryAt);
        $outbox = $outbox->fresh();

        $this->assertSame(ClosingReportOutbox::STATUS_FAILED, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame('Connection refused', $outbox->last_error);
        $this->assertTrue($outbox->next_attempt_at->equalTo($retryAt));
        $this->assertNull($outbox->published_at);
    }

    public function test_due_selects_only_rows_ready_for_another_attempt(): void
    {
        Carbon::setTestNow('2026-10-02 22:15:00');

        $outlets = collect(['A', 'B', 'C', 'D', 'E', 'F'])
            ->map(fn (string $code) => $this->createOutlet(['code' => $code, 'name' => $code]));
        $report = fn (int $i) => $this->createReport(['outlet_id' => $outlets[$i]->id]);

        $fresh = $this->createOutbox($report(0));
        $retryNow = $this->createOutbox($report(1), [
            'status' => ClosingReportOutbox::STATUS_FAILED, 'attempts' => 1,
            'next_attempt_at' => now()->subSecond(),
        ]);
        $this->createOutbox($report(2), [
            'status' => ClosingReportOutbox::STATUS_FAILED, 'attempts' => 1,
            'next_attempt_at' => now()->addMinute(),
        ]);
        $this->createOutbox($report(3), [
            'status' => ClosingReportOutbox::STATUS_PUBLISHED, 'attempts' => 1,
            'published_at' => now(),
        ]);
        $this->createOutbox($report(4), [
            'status' => ClosingReportOutbox::STATUS_FAILED, 'attempts' => 5,
            'next_attempt_at' => now()->subMinute(),
        ]);
        $retryAtExactlyNow = $this->createOutbox($report(5), [
            'status' => ClosingReportOutbox::STATUS_FAILED, 'attempts' => 4,
            'next_attempt_at' => now(),
        ]);

        $due = ClosingReportOutbox::due(maxAttempts: 5)->pluck('id')->sort()->values()->all();

        $expected = collect([$fresh->id, $retryNow->id, $retryAtExactlyNow->id])->sort()->values()->all();
        $this->assertSame($expected, $due);
    }
}
