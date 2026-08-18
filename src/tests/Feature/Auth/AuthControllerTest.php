<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name'       => 'John Doe',
            'email'      => 'john@example.com',
            'password'   => 'secret123',
            'role'       => 'admin',
            'departemen' => 'Operation',
            'outlet'     => ['bandung'],
            'module_app' => ['app', 'admin'],
            'pin'        => '654321',
        ], $overrides));
    }

    /**
     * `/register` bukan lagi route publik — ia membuat user dengan `role` dan
     * `module_app` dari payload, jadi harus dijaga seperti user management.
     */
    private function actingAsAdmin(): User
    {
        $admin = $this->createUser(['email' => 'admin+register@example.com']);

        $this->actingAs($admin, 'api');

        return $admin;
    }

    public function test_register_is_closed_to_guests(): void
    {
        $this->postJson('/api/register', [
            'name'                  => 'Penyusup',
            'email'                 => 'penyusup@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'admin',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['admin'],
        ])->assertStatus(401);

        $this->assertDatabaseMissing('users', ['email' => 'penyusup@example.com']);
    }

    public function test_register_is_closed_to_non_admin_roles(): void
    {
        $this->actingAs($this->createUser([
            'email' => 'kitchen@example.com',
            'role'  => 'kitchen',
        ]), 'api');

        $this->postJson('/api/register', [
            'name'                  => 'Penyusup',
            'email'                 => 'penyusup2@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'admin',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['admin'],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'penyusup2@example.com']);
    }

    public function test_register_rejects_unknown_role(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/register', [
            'name'                  => 'Jane',
            'email'                 => 'jane3@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'superuser',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['kitchen'],
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_register_rejects_unknown_module_app(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/register', [
            'name'                  => 'Jane',
            'email'                 => 'jane4@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'kitchen',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['cmms'],
        ])->assertStatus(422)->assertJsonValidationErrors(['module_app.0']);
    }

    public function test_register_creates_user_and_returns_token(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/register', [
            'name'                  => 'Jane',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'kitchen',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['kitchen'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'role'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertTrue(Hash::check('secret123', User::where('email', 'jane@example.com')->first()->password));
    }

    public function test_register_validates_required_fields(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role', 'departemen', 'outlet', 'module_app']);
    }

    public function test_register_rejects_unconfirmed_password(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/register', [
            'name'                  => 'Jane',
            'email'                 => 'jane2@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'different',
            'role'                  => 'kitchen',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['kitchen'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/login', [
            'email'    => 'john@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonStructure(['data' => ['user', 'token', 'expires_in']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/login', [
            'email'    => 'john@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJsonPath('status', false);
    }

    public function test_login_validates_input(): void
    {
        $response = $this->postJson('/api/login', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_by_pin_succeeds(): void
    {
        $this->createUser(['pin' => '999888']);

        $response = $this->postJson('/api/login-pin', ['pin' => '999888']);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['user', 'token', 'expires_in']]);
    }

    public function test_login_by_pin_fails_for_unknown_pin(): void
    {
        $this->createUser(['pin' => '999888']);

        $response = $this->postJson('/api/login-pin', ['pin' => '000000']);

        $response->assertStatus(401)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'PIN tidak valid');
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_succeeds_for_authenticated_user(): void
    {
        $user = $this->createUser();
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout');

        $response->assertOk()->assertJsonPath('message', 'Successfully logged out');
    }
}
