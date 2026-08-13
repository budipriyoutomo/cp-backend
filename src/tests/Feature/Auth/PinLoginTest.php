<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * PIN login for kitchen tablets.
 *
 * The PIN used to be stored, queried and shipped inside the JWT in plaintext.
 * It is now a bcrypt hash plus a keyed HMAC blind index (`pin_lookup`) that
 * keeps the lookup a single indexed query.
 */
class PinLoginTest extends TestCase
{
    use RefreshDatabase;

    private function kitchenUser(string $pin, array $overrides = []): User
    {
        return User::create(array_merge([
            'name'       => 'Dapur 1',
            'email'      => 'dapur1@example.com',
            'password'   => 'secret123',
            'role'       => 'kitchen',
            'departemen' => 'Kitchen',
            'outlet'     => ['bandung'],
            'module_app' => ['kitchen'],
            // The model mutator hashes the PIN and derives pin_lookup.
            'pin'        => $pin,
        ], $overrides));
    }

    public function test_login_with_a_correct_pin_returns_a_token(): void
    {
        $user = $this->kitchenUser('123456');

        $this->postJson('/api/login-pin', ['pin' => '123456'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'expires_in']]);
    }

    public function test_login_with_a_wrong_pin_is_rejected(): void
    {
        $this->kitchenUser('123456');

        $this->postJson('/api/login-pin', ['pin' => '999999'])
            ->assertStatus(401)
            ->assertJsonPath('status', false);
    }

    public function test_the_pin_is_never_stored_in_plaintext(): void
    {
        $this->kitchenUser('123456');

        $stored = \Illuminate\Support\Facades\DB::table('users')->first();

        $this->assertNotSame('123456', $stored->pin);
        $this->assertStringStartsWith('$2y$', $stored->pin);
        // The blind index is a keyed HMAC, not the PIN either.
        $this->assertNotSame('123456', $stored->pin_lookup);
        $this->assertSame(64, strlen($stored->pin_lookup));
        $this->assertTrue(Hash::check('123456', $stored->pin));
    }

    public function test_the_pin_is_not_in_the_jwt_payload(): void
    {
        $user = $this->kitchenUser('123456');

        $token   = JWTAuth::fromUser($user);
        $payload = json_decode(base64_decode(explode('.', $token)[1]), true);

        // A JWT payload is only base64 encoded — anything here is readable by
        // whoever holds the token.
        $this->assertArrayNotHasKey('pin', $payload);
        $this->assertSame('kitchen', $payload['role']);
    }

    public function test_the_pin_is_not_exposed_in_api_responses(): void
    {
        $this->kitchenUser('123456');

        $response = $this->postJson('/api/login-pin', ['pin' => '123456'])->assertOk();

        $this->assertStringNotContainsString('123456', $response->getContent());
        $this->assertStringNotContainsString('pin_lookup', $response->getContent());
    }

    public function test_a_user_without_a_pin_cannot_login_by_pin(): void
    {
        User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secret123',
            'role'     => 'admin',
        ]);

        $this->postJson('/api/login-pin', ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_pin_login_is_rate_limited(): void
    {
        $this->kitchenUser('123456');

        // 20 attempts per minute; the 21st is throttled even though the global
        // limit is 60/min. The limit is keyed by IP, and one outlet's tablets
        // share an IP, so it has to leave room for a shift change with typos.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/login-pin', ['pin' => '000000'])->assertStatus(401);
        }

        $this->postJson('/api/login-pin', ['pin' => '000000'])->assertStatus(429);
    }

    public function test_pin_validation_still_applies(): void
    {
        $this->postJson('/api/login-pin', ['pin' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_a_pin_shorter_than_the_login_minimum_cannot_be_created(): void
    {
        // User management used to accept min:4 while login required min:6, so a
        // 4- or 5-digit PIN could be issued and then never work.
        $admin = User::create([
            'name'       => 'Admin',
            'email'      => 'admin@example.com',
            'password'   => 'secret123',
            'role'       => 'admin',
            'module_app' => ['admin'],
        ]);

        $this->actingAs($admin, 'api')->postJson('/api/users', [
            'name'     => 'Dapur 2',
            'email'    => 'dapur2@example.com',
            'password' => 'secret123',
            'role'     => 'kitchen',
            'pin'      => '1234',
        ])->assertStatus(422)->assertJsonValidationErrors(['pin']);
    }

    public function test_a_pin_cannot_be_reused_by_another_user(): void
    {
        $this->kitchenUser('123456');

        $admin = User::create([
            'name'       => 'Admin',
            'email'      => 'admin@example.com',
            'password'   => 'secret123',
            'role'       => 'admin',
            'module_app' => ['admin'],
        ]);

        // Uniqueness is enforced against the blind index, not the bcrypt column.
        $this->actingAs($admin, 'api')->postJson('/api/users', [
            'name'     => 'Dapur 2',
            'email'    => 'dapur2@example.com',
            'password' => 'secret123',
            'role'     => 'kitchen',
            'pin'      => '123456',
        ])->assertStatus(422)->assertJsonValidationErrors(['pin']);
    }
}
