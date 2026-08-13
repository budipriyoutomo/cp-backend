<?php

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class WasteTest extends TestCase
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

    public function test_waste_store_records_waste_for_each_item(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $response = $this->postJson('/api/production/waste', [
            'itemIds' => [$item->id],
            'reason'  => 'Jatuh ke lantai',
        ]);

        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('waste_records', [
            'production_item_id' => $item->id,
            'menu_id'            => $menu->id,
            'outlet_id'          => $outlet->id,
            'reason'             => 'Jatuh ke lantai',
            'quantity'           => 1,
        ]);
    }

    public function test_waste_store_requires_reason_and_an_item(): void
    {
        $this->postJson('/api/production/waste', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'itemId']);
    }

    public function test_waste_index_returns_records_within_date_range(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $this->postJson('/api/production/waste', [
            'itemIds' => [$item->id],
            'reason'  => 'Basi',
        ])->assertOk();

        $today = now()->toDateString();

        $this->getJson("/api/production/waste?outletId={$outlet->id}&startDate={$today}&endDate={$today}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_waste_index_excludes_records_outside_range(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $this->postJson('/api/production/waste', [
            'itemIds' => [$item->id],
            'reason'  => 'Basi',
        ])->assertOk();

        $this->getJson("/api/production/waste?outletId={$outlet->id}&startDate=2020-01-01&endDate=2020-01-02")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_waste_index_validates_query_params(): void
    {
        $this->getJson('/api/production/waste')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId', 'startDate', 'endDate']);
    }
}
