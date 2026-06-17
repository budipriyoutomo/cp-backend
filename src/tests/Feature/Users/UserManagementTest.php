<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secret123',
            'role'     => 'admin',
        ]);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->admin();
        User::create(['name' => 'Chef', 'email' => 'chef@example.com', 'password' => 'secret123', 'role' => 'kitchen']);

        $this->actingAs($admin, 'api')->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'hasPin']]]);
    }

    public function test_index_can_filter_by_role(): void
    {
        $admin = $this->admin();
        User::create(['name' => 'Chef', 'email' => 'chef@example.com', 'password' => 'secret123', 'role' => 'kitchen']);

        $this->actingAs($admin, 'api')->getJson('/api/users?role=kitchen')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role', 'kitchen');
    }

    public function test_admin_can_create_user_with_hashed_password(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->postJson('/api/users', [
            'name'     => 'Manager',
            'email'    => 'manager@example.com',
            'password' => 'secret123',
            'role'     => 'manager',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'manager@example.com')
            ->assertJsonPath('data.role', 'manager');

        $this->assertDatabaseHas('users', ['email' => 'manager@example.com', 'role' => 'manager']);
        $created = User::where('email', 'manager@example.com')->first();
        $this->assertTrue(Hash::check('secret123', $created->password));
    }

    public function test_create_validates_and_rejects_duplicate_email(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'api')->postJson('/api/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);

        $this->actingAs($admin, 'api')->postJson('/api/users', [
            'name'     => 'Dup',
            'email'    => 'admin@example.com',
            'password' => 'secret123',
            'role'     => 'manager',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_update_user_without_changing_password(): void
    {
        $admin = $this->admin();
        $user  = User::create([
            'name' => 'Old', 'email' => 'old@example.com', 'password' => Hash::make('original'), 'role' => 'kitchen',
        ]);

        $this->actingAs($admin, 'api')->putJson("/api/users/{$user->id}", [
            'name' => 'New Name',
            'role' => 'service',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('service', $user->role);
        // Password is unchanged when omitted.
        $this->assertTrue(Hash::check('original', $user->password));
    }

    public function test_update_changes_password_when_provided(): void
    {
        $admin = $this->admin();
        $user  = User::create([
            'name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('original'), 'role' => 'kitchen',
        ]);

        $this->actingAs($admin, 'api')->putJson("/api/users/{$user->id}", [
            'password' => 'newsecret',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('newsecret', $user->password));
    }

    public function test_admin_can_delete_other_user(): void
    {
        $admin = $this->admin();
        $user  = User::create(['name' => 'Bye', 'email' => 'bye@example.com', 'password' => 'secret123', 'role' => 'kitchen']);

        $this->actingAs($admin, 'api')->deleteJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('message', 'User deleted');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'api')->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $kitchen = User::create(['name' => 'Chef', 'email' => 'chef@example.com', 'password' => 'secret123', 'role' => 'kitchen']);

        $this->actingAs($kitchen, 'api')->getJson('/api/users')->assertStatus(403);
        $this->actingAs($kitchen, 'api')->postJson('/api/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'role' => 'kitchen',
        ])->assertStatus(403);
    }

    public function test_guest_cannot_access_user_management(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }
}
