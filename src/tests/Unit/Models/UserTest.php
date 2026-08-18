<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_jwt_identifier_returns_primary_key(): void
    {
        $user = new User();
        $user->id = 99;

        $this->assertSame(99, $user->getJWTIdentifier());
    }

    public function test_jwt_custom_claims_expose_authorization_attributes(): void
    {
        $user = new User([
            'role'       => 'admin',
            'departemen' => 'Operation',
            'outlet'     => ['bandung'],
            'module_app' => ['app', 'admin'],
            'pin'        => '123456',
        ]);

        $claims = $user->getJWTCustomClaims();

        $this->assertSame('admin', $claims['role']);
        $this->assertSame('Operation', $claims['departemen']);
        $this->assertSame(['bandung'], $claims['outlet']);
        $this->assertSame(['app', 'admin'], $claims['module_app']);

        // The PIN must never ride along: a JWT payload is base64, not encrypted.
        $this->assertArrayNotHasKey('pin', $claims);
    }

    public function test_setting_a_pin_hashes_it_and_derives_the_blind_index(): void
    {
        $user = new User(['pin' => '123456']);

        $attributes = $user->getAttributes();

        $this->assertNotSame('123456', $attributes['pin']);
        $this->assertTrue(Hash::check('123456', $attributes['pin']));
        $this->assertSame(User::pinLookup('123456'), $attributes['pin_lookup']);
    }

    public function test_clearing_the_pin_clears_the_blind_index_too(): void
    {
        $user = new User(['pin' => '123456']);
        $user->pin = null;

        $this->assertNull($user->getAttributes()['pin']);
        $this->assertNull($user->getAttributes()['pin_lookup']);
    }

    public function test_pin_and_lookup_are_hidden_from_serialization(): void
    {
        $user = new User(['name' => 'Dapur', 'pin' => '123456']);

        $this->assertArrayNotHasKey('pin', $user->toArray());
        $this->assertArrayNotHasKey('pin_lookup', $user->toArray());
    }

    public function test_outlet_and_module_app_are_cast_to_array(): void
    {
        $user = new User();
        $user->outlet = ['bandung', 'jakarta'];
        $user->module_app = ['kitchen', 'report'];

        // Round-trip through the attribute casting layer.
        $this->assertSame(['bandung', 'jakarta'], $user->outlet);
        $this->assertSame(['kitchen', 'report'], $user->module_app);

        // The raw stored value should be JSON encoded.
        $this->assertJson($user->getAttributes()['outlet']);
    }

    public function test_password_is_hidden_in_array_serialization(): void
    {
        $user = new User([
            'name'     => 'Tester',
            'email'    => 'tester@example.com',
            'password' => 'plain-secret',
        ]);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }
}
