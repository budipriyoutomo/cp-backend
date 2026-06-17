<?php

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class ProductionPlanTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    public function test_save_plan_persists_plan_and_items(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $response = $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                [
                    'timeSlot' => '08:00-09:00',
                    'items'    => [
                        ['plateColorId' => $color->id, 'qty' => 5],
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('message', 'Plan saved');

        $this->assertDatabaseHas('production_plans', [
            'outlet_id' => $outlet->id,
            'time_slot' => '08:00-09:00',
        ]);
        $this->assertDatabaseHas('production_plan_items', [
            'plate_color' => $color->id,
            'qty'         => 5,
        ]);
    }

    public function test_save_plan_is_idempotent_on_upsert(): void
    {
        $outlet  = $this->createOutlet();
        $color   = $this->createPlateColor(['platename' => 'Hijau']);
        $payload = [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                [
                    'timeSlot' => '08:00-09:00',
                    'items'    => [['plateColorId' => $color->id, 'qty' => 5]],
                ],
            ],
        ];

        $this->postJson('/api/production/plan', $payload)->assertOk();
        $this->postJson('/api/production/plan', $payload)->assertOk();

        // The same time slot must not be duplicated (unique upsert on outlet+date+time_slot).
        $this->assertDatabaseCount('production_plans', 1);

        // Items use soft deletes, so the previous batch is soft-deleted and a new one inserted.
        // Only one item should remain active; the raw table still holds the trashed row.
        $this->assertSame(1, \App\Models\ProductionPlanItem::count());
        $this->assertDatabaseCount('production_plan_items', 2);
    }

    public function test_save_plan_validates_payload(): void
    {
        $this->postJson('/api/production/plan', ['plan' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    public function test_get_plan_returns_formatted_rows(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                [
                    'timeSlot' => '08:00-09:00',
                    'items'    => [['plateColorId' => $color->id, 'qty' => 5]],
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/production/plan?outletId=' . $outlet->id . '&date=2026-06-17')
            ->assertOk()
            ->assertJsonPath('data.0.timeSlot', '08:00-09:00')
            ->assertJsonPath('data.0.hijau', 5);
    }
}
