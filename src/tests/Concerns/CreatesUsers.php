<?php

namespace Tests\Concerns;

use App\Models\User;

trait CreatesUsers
{
    protected function userWithRole(string $role): User
    {
        return User::create([
            'name'       => ucfirst($role),
            'email'      => $role . '+' . uniqid() . '@example.com',
            'password'   => 'secret123',
            'role'       => $role,
            'departemen' => 'Operation',
            'outlet'     => ['bandung'],
            'module_app' => ['cmms'],
        ]);
    }

    /**
     * Authenticate the test as a user with the given role.
     *
     * Every /production, /reports, /sales, /closing-reports and /waste route is
     * behind auth:api, so feature tests for those modules need a signed-in user.
     */
    protected function actingAsRole(string $role = 'admin'): User
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user, 'api');

        return $user;
    }
}
