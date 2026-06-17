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
}
