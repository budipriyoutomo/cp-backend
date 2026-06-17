<?php

namespace Tests\Unit\Models;

use App\Models\User;
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
            'module_app' => ['cmms'],
            'pin'        => '123456',
        ]);

        $claims = $user->getJWTCustomClaims();

        $this->assertSame('admin', $claims['role']);
        $this->assertSame('Operation', $claims['departemen']);
        $this->assertSame(['bandung'], $claims['outlet']);
        $this->assertSame(['cmms'], $claims['module_app']);
        $this->assertSame('123456', $claims['pin']);
    }

    public function test_outlet_and_module_app_are_cast_to_array(): void
    {
        $user = new User();
        $user->outlet = ['bandung', 'jakarta'];
        $user->module_app = ['cmms', 'pos'];

        // Round-trip through the attribute casting layer.
        $this->assertSame(['bandung', 'jakarta'], $user->outlet);
        $this->assertSame(['cmms', 'pos'], $user->module_app);

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
