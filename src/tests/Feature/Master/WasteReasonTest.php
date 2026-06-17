<?php

namespace Tests\Feature\Master;

use App\Models\WasteReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class WasteReasonTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_admin_can_create_waste_reason(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name' => 'Basi',
            'description' => 'Kadaluarsa',
        ])->assertCreated()->assertJsonPath('data.reason_name', 'Basi');

        $this->assertDatabaseHas('waste_reasons', ['reason_name' => 'Basi']);
    }

    public function test_create_requires_reason_name(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason_name']);
    }

    public function test_create_rejects_duplicate_reason_name(): void
    {
        $admin = $this->userWithRole('admin');
        WasteReason::create(['reason_name' => 'Basi']);

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name' => 'Basi',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason_name']);
    }

    public function test_admin_can_update_waste_reason(): void
    {
        $admin  = $this->userWithRole('admin');
        $reason = WasteReason::create(['reason_name' => 'Basi']);

        $this->actingAs($admin, 'api')->putJson("/api/master/waste-reason/{$reason->id}", [
            'reason_name' => 'Kadaluarsa',
        ])->assertOk()->assertJsonPath('data.reason_name', 'Kadaluarsa');

        $this->assertDatabaseHas('waste_reasons', ['id' => $reason->id, 'reason_name' => 'Kadaluarsa']);
    }

    public function test_admin_can_delete_waste_reason(): void
    {
        $admin  = $this->userWithRole('admin');
        $reason = WasteReason::create(['reason_name' => 'Basi']);

        $this->actingAs($admin, 'api')->deleteJson("/api/master/waste-reason/{$reason->id}")
            ->assertOk();

        $this->assertSoftDeleted('waste_reasons', ['id' => $reason->id]);
    }

    public function test_non_admin_cannot_create_waste_reason(): void
    {
        $kitchen = $this->userWithRole('kitchen');

        $this->actingAs($kitchen, 'api')->postJson('/api/master/waste-reason', [
            'reason_name' => 'Jatuh',
        ])->assertStatus(403);
    }

    public function test_guest_cannot_access_waste_reasons(): void
    {
        $this->getJson('/api/master/waste-reason')->assertStatus(401);
    }
}
