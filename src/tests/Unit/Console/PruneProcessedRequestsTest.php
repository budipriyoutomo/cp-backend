<?php

namespace Tests\Unit\Console;

use App\Models\ProcessedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * processed_requests stores one row per successful mutation, each carrying the
 * full response body. Without pruning it grows without bound.
 */
class PruneProcessedRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $requestId, int $daysAgo): ProcessedRequest
    {
        $row = ProcessedRequest::create([
            'request_id'      => $requestId,
            'method'          => 'POST',
            'path'            => 'api/production/produce',
            'response_status' => 200,
            'response_body'   => '{"status":true}',
        ]);

        // created_at is filled by the model, so age is applied afterwards.
        $row->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $row;
    }

    public function test_it_deletes_records_older_than_the_retention_window(): void
    {
        $this->record('old', 10);
        $this->record('recent', 2);

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertDatabaseMissing('processed_requests', ['request_id' => 'old']);
        $this->assertDatabaseHas('processed_requests', ['request_id' => 'recent']);
    }

    public function test_the_retention_window_is_configurable(): void
    {
        $this->record('three-days', 3);

        $this->artisan('idempotency:prune', ['--days' => 30])->assertSuccessful();
        $this->assertDatabaseHas('processed_requests', ['request_id' => 'three-days']);

        $this->artisan('idempotency:prune', ['--days' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('processed_requests', ['request_id' => 'three-days']);
    }

    public function test_a_record_exactly_at_the_boundary_is_kept(): void
    {
        // Time is frozen so "exactly 7 days old" really means exactly, instead
        // of depending on whether the write and the cutoff land in the same second.
        Carbon::setTestNow('2026-06-17 10:00:00');

        // Retention is "younger than N days", so a 7-day-old row on a 7-day
        // window must survive — replays are still legitimate up to the cutoff.
        $this->record('boundary', 7);
        $this->record('one-second-older', 7)
            ->forceFill(['created_at' => now()->subDays(7)->subSecond()])
            ->saveQuietly();

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertDatabaseHas('processed_requests', ['request_id' => 'boundary']);
        $this->assertDatabaseMissing('processed_requests', ['request_id' => 'one-second-older']);

        Carbon::setTestNow();
    }

    public function test_it_refuses_a_zero_or_negative_retention(): void
    {
        $this->record('today', 0);

        $this->artisan('idempotency:prune', ['--days' => 0])->assertFailed();

        $this->assertDatabaseHas('processed_requests', ['request_id' => 'today']);
    }

    public function test_it_deletes_more_than_one_chunk(): void
    {
        // The command deletes in batches of 1000; make sure the loop keeps
        // going instead of stopping after the first batch.
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = [
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'request_id'      => "bulk-{$i}",
                'method'          => 'POST',
                'path'            => 'api/production/produce',
                'response_status' => 200,
                'response_body'   => '{}',
                'created_at'      => now()->subDays(30),
                'updated_at'      => now()->subDays(30),
            ];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            ProcessedRequest::insert($chunk);
        }

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertSame(0, ProcessedRequest::count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'idempotency:prune'));

        $this->assertCount(1, $events, 'idempotency:prune should be registered on the scheduler.');
        $this->assertSame('15 3 * * *', $events->first()->expression);
    }
}
