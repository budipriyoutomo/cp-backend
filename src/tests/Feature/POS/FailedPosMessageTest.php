<?php

namespace Tests\Feature\POS;

use App\Models\FailedPosMessage;
use App\Models\POSData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Dead letters for POS ingest.
 *
 * A message that cannot be mapped used to be nacked with requeue=false and no
 * dead-letter exchange, so it vanished. Renaming a plate color would silently
 * break a day of POS data with no way to recover it.
 *
 * The AMQP loop itself is not exercised here — it is an infinite connection
 * loop. What is tested is the part with the logic: parking a payload and
 * replaying it once the master data is fixed.
 */
class FailedPosMessageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private function park(array $data, string $error = 'Plate color not found: ungu'): FailedPosMessage
    {
        return FailedPosMessage::create([
            'payload' => json_encode(['data' => $data]),
            'error'   => $error,
        ]);
    }

    public function test_event_data_is_recovered_from_the_stored_payload(): void
    {
        $message = $this->park([
            'platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 4,
        ]);

        $this->assertSame('Ungu', $message->eventData()['platecolor']);
        $this->assertSame(4, $message->eventData()['sold']);
    }

    public function test_event_data_is_null_for_an_unusable_body(): void
    {
        $message = FailedPosMessage::create([
            'payload' => 'not json at all',
            'error'   => 'Invalid payload',
        ]);

        $this->assertNull($message->eventData());
    }

    public function test_replay_processes_a_message_once_the_master_data_exists(): void
    {
        $outlet = $this->createOutlet(['code' => 'BDG']);
        $message = $this->park([
            'platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 4,
        ]);

        // The colour that was missing when the message first arrived.
        $color = $this->createPlateColor(['platename' => 'Ungu']);

        $this->artisan('pos:replay-failed')->assertSuccessful();

        $this->assertNotNull($message->fresh()->resolved_at);
        $this->assertDatabaseHas('posdata', [
            'plate_color_id' => $color->id,
            'outlet_id'      => $outlet->id,
            'sold'           => 4,
        ]);
    }

    public function test_replay_keeps_a_message_that_still_cannot_be_mapped(): void
    {
        $this->createOutlet(['code' => 'BDG']);
        $message = $this->park([
            'platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 4,
        ]);

        // Master data still not fixed.
        $this->artisan('pos:replay-failed')->assertFailed();

        $message->refresh();
        $this->assertNull($message->resolved_at);
        $this->assertSame(2, $message->attempts);
        $this->assertStringContainsString('Plate color not found', $message->error);
        $this->assertSame(0, POSData::count());
    }

    public function test_replay_skips_messages_that_are_already_resolved(): void
    {
        $this->createOutlet(['code' => 'BDG']);
        $this->createPlateColor(['platename' => 'Ungu']);

        $done = $this->park([
            'platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 4,
        ]);
        $done->update(['resolved_at' => now()]);

        $this->artisan('pos:replay-failed')
            ->expectsOutputToContain('Tidak ada pesan POS gagal')
            ->assertSuccessful();

        $this->assertSame(0, POSData::count());
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->createOutlet(['code' => 'BDG']);
        $this->createPlateColor(['platename' => 'Ungu']);

        $message = $this->park([
            'platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 4,
        ]);

        $this->artisan('pos:replay-failed', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($message->fresh()->resolved_at);
        $this->assertSame(0, POSData::count());
    }

    public function test_a_single_message_can_be_replayed_by_id(): void
    {
        $this->createOutlet(['code' => 'BDG']);
        $this->createPlateColor(['platename' => 'Ungu']);

        $first  = $this->park(['platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 4]);
        $second = $this->park(['platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-18', 'sold' => 9]);

        $this->artisan('pos:replay-failed', ['--id' => $first->id])->assertSuccessful();

        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertNull($second->fresh()->resolved_at);
        $this->assertSame(1, POSData::count());
    }
}
