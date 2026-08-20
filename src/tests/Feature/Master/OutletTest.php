<?php

namespace Tests\Feature\Master;

use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class OutletTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_admin_can_create_outlet(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/outlet', [
            'code' => 'BDG',
            'name' => 'Bandung',
        ])->assertCreated()->assertJsonPath('data.code', 'BDG');

        $this->assertDatabaseHas('outlets', ['code' => 'BDG', 'name' => 'Bandung']);
    }

    public function test_create_requires_code_and_name(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/outlet', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $admin = $this->userWithRole('admin');
        Outlet::create(['code' => 'BDG', 'name' => 'Bandung']);

        $this->actingAs($admin, 'api')->postJson('/api/master/outlet', [
            'code' => 'BDG',
            'name' => 'Bandung 2',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_admin_can_update_outlet(): void
    {
        $admin  = $this->userWithRole('admin');
        $outlet = Outlet::create(['code' => 'BDG', 'name' => 'Bandung']);

        $this->actingAs($admin, 'api')->putJson("/api/master/outlet/{$outlet->id}", [
            'code' => 'BDG',
            'name' => 'Bandung Kota',
        ])->assertOk()->assertJsonPath('data.name', 'Bandung Kota');

        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'name' => 'Bandung Kota']);
    }

    public function test_admin_can_delete_outlet(): void
    {
        $admin  = $this->userWithRole('admin');
        $outlet = Outlet::create(['code' => 'BDG', 'name' => 'Bandung']);

        $this->actingAs($admin, 'api')->deleteJson("/api/master/outlet/{$outlet->id}")
            ->assertOk();

        $this->assertSoftDeleted('outlets', ['id' => $outlet->id]);
    }

    public function test_non_admin_cannot_create_outlet(): void
    {
        $kitchen = $this->userWithRole('kitchen');

        $this->actingAs($kitchen, 'api')->postJson('/api/master/outlet', [
            'code' => 'JKT',
            'name' => 'Jakarta',
        ])->assertStatus(403);
    }

    public function test_guest_cannot_access_outlets(): void
    {
        $this->getJson('/api/master/outlet')->assertStatus(401);
        $this->postJson('/api/master/outlet', ['code' => 'X', 'name' => 'Y'])->assertStatus(401);
    }
}
