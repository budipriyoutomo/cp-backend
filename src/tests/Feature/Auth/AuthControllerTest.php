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
            'module_app' => ['cmms'],
            'pin'        => '654321',
        ], $overrides));
    }

    public function test_register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name'                  => 'Jane',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'kitchen',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['pos'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'role'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertTrue(Hash::check('secret123', User::where('email', 'jane@example.com')->first()->password));
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role', 'departemen', 'outlet', 'module_app']);
    }

    public function test_register_rejects_unconfirmed_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name'                  => 'Jane',
            'email'                 => 'jane2@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'different',
            'role'                  => 'kitchen',
            'departemen'            => 'Operation',
            'outlet'                => ['bandung'],
            'module_app'            => ['pos'],
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
            ->assertJsonPath('success', true)
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

        $response->assertStatus(401)->assertJsonPath('success', false);
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
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'token', 'expires_in']]);
    }

    public function test_login_by_pin_fails_for_unknown_pin(): void
    {
        $this->createUser(['pin' => '999888']);

        $response = $this->postJson('/api/login-pin', ['pin' => '000000']);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'PIN tidak valid');
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
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
