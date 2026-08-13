<?php

namespace Tests\Feature\Sales;

use App\Models\SalesHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class SalesTest extends TestCase
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

    /**
     * Build a valid store payload, seeding the master data it references.
     */
    private function salesPayload(array $overrides = []): array
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $menu   = $this->createMenu($color);

        return array_merge([
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'draft',
            'items'     => [
                [
                    'plate_color_id'   => $color->id,
                    'pos_sold'         => 10,
                    'production_sold'  => 5,
                    'production_waste' => 1,
                    'adjustment'       => 1,
                    'compensation'     => 2,
                    'details'          => [
                        [
                            'menu_id'        => $menu->id,
                            'menu_name'      => $menu->menuname,
                            'total_produced' => 8,
                            'total_sold'     => 5,
                            'total_wasted'   => 1,
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_store_creates_header_items_and_details(): void
    {
        $payload = $this->salesPayload();

        $response = $this->postJson('/api/sales', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.items.0.details');

        $this->assertDatabaseHas('sales_headers', [
            'outlet_id' => $payload['outlet_id'],
            'status'    => 'draft',
        ]);
        $this->assertDatabaseCount('sales_items', 1);
        $this->assertDatabaseCount('sales_item_details', 1);
    }

    public function test_store_recomputes_selisih(): void
    {
        // selisih = pos_sold - (production_sold + adjustment + compensation)
        //         = 10 - (5 + 1 + 2) = 2
        $this->postJson('/api/sales', $this->salesPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.selisih', 2);

        $this->assertDatabaseHas('sales_items', ['selisih' => 2]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->postJson('/api/sales', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outlet_id', 'date', 'status', 'items']);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $payload = $this->salesPayload(['status' => 'unknown']);

        $this->postJson('/api/sales', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_non_existent_plate_color(): void
    {
        $payload = $this->salesPayload();
        $payload['items'][0]['plate_color_id'] = '11111111-1111-1111-1111-111111111111';

        $this->postJson('/api/sales', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.plate_color_id']);
    }

    public function test_drafts_lists_sales_with_pagination(): void
    {
        $this->postJson('/api/sales', $this->salesPayload())->assertStatus(201);

        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'date', 'outlet_name', 'items']],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_show_returns_sales_with_items(): void
    {
        $this->postJson('/api/sales', $this->salesPayload())->assertStatus(201);
        $id = SalesHeader::first()->id;

        $this->getJson("/api/sales/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $this->getJson('/api/sales/11111111-1111-1111-1111-111111111111')
            ->assertStatus(404);
    }

    public function test_by_date_returns_matching_sales(): void
    {
        $payload = $this->salesPayload();
        $this->postJson('/api/sales', $payload)->assertStatus(201);

        $this->getJson('/api/sales/by-date?outlet_id=' . $payload['outlet_id'] . '&date=2026-06-17')
            ->assertOk()
            ->assertJsonPath('data.outlet_id', $payload['outlet_id'])
            ->assertJsonCount(1, 'data.items');
    }

    public function test_by_date_validates_query_params(): void
    {
        // Reaches the byDate action (which validates) rather than being captured
        // by the /{id} wildcard route.
        $this->getJson('/api/sales/by-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outlet_id', 'date']);
    }
}
