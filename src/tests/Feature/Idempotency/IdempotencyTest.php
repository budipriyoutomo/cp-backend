<?php

namespace Tests\Feature\Idempotency;

use App\Models\SalesHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        // Every route exercised here now sits behind auth:api.
        $this->actingAsRole('admin');
    }

    private function payload(): array
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        return [
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'draft',
            'items'     => [
                ['plate_color_id' => $color->id, 'pos_sold' => 5, 'production_sold' => 5],
            ],
        ];
    }

    public function test_replays_stored_response_for_same_request_id_without_duplicating(): void
    {
        $payload = $this->payload();

        $first = $this->withHeaders(['X-Client-Request-Id' => 'req-1'])
            ->postJson('/api/sales', $payload);
        $first->assertStatus(201);

        // Same request id -> replayed, no second row created.
        $second = $this->withHeaders(['X-Client-Request-Id' => 'req-1'])
            ->postJson('/api/sales', $payload);
        $second->assertStatus(201)->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('sales_headers', 1);
        $this->assertDatabaseCount('processed_requests', 1);
    }

    public function test_different_request_id_executes_again_instead_of_replaying(): void
    {
        $payload = $this->payload();

        $this->withHeaders(['X-Client-Request-Id' => 'req-1'])
            ->postJson('/api/sales', $payload)->assertStatus(201);

        $changed = $payload;
        $changed['items'][0]['pos_sold'] = 99;

        $this->withHeaders(['X-Client-Request-Id' => 'req-2'])
            ->postJson('/api/sales', $changed)
            ->assertStatus(201)
            ->assertHeaderMissing('X-Idempotent-Replay');

        // Sales headers are unique per (outlet, date), so "executed again" shows
        // up as an updated row rather than a second one.
        $this->assertDatabaseCount('sales_headers', 1);
        $this->assertDatabaseHas('sales_items', ['pos_sold' => 99]);
        $this->assertDatabaseCount('processed_requests', 2);
    }

    public function test_request_without_header_is_not_tracked(): void
    {
        $payload = $this->payload();

        $this->postJson('/api/sales', $payload)->assertStatus(201);

        $changed = $payload;
        $changed['items'][0]['pos_sold'] = 77;

        $this->postJson('/api/sales', $changed)->assertStatus(201);

        // Nothing recorded, and the second call was executed rather than replayed.
        $this->assertDatabaseCount('processed_requests', 0);
        $this->assertDatabaseHas('sales_items', ['pos_sold' => 77]);
    }

    public function test_validation_errors_are_not_cached(): void
    {
        // Invalid payload with a request id -> 422, must NOT be stored.
        $this->withHeaders(['X-Client-Request-Id' => 'req-x'])
            ->postJson('/api/sales', [])
            ->assertStatus(422);

        $this->assertDatabaseCount('processed_requests', 0);

        // A later valid request reusing that id should execute (not replay a 422).
        $this->withHeaders(['X-Client-Request-Id' => 'req-x'])
            ->postJson('/api/sales', $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseCount('sales_headers', 1);
        $this->assertDatabaseCount('processed_requests', 1);
    }

    public function test_get_requests_are_not_affected(): void
    {
        $outlet = $this->createOutlet();

        // A GET carrying the header should just pass through (no tracking).
        $this->withHeaders(['X-Client-Request-Id' => 'req-get'])
            ->getJson('/api/sales?outlet_id=' . $outlet->id)
            ->assertOk();

        $this->assertDatabaseCount('processed_requests', 0);
    }
}
