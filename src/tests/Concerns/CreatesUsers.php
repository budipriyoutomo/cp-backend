<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Support\AccessOptions;

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
            'module_app' => self::modulesForRole($role),
        ]);
    }

    /**
     * Setiap role kecuali `manager` punya modul bernama sama. `app` selalu ikut
     * sebagai modul dasar.
     *
     * Fixture lama menulis `['cmms']` — modul dari aplikasi lain yang tidak
     * pernah ada di sistem ini, jadi tidak ada test yang benar-benar menguji
     * apa pun soal akses modul.
     *
     * @return array<int, string>
     */
    protected static function modulesForRole(string $role): array
    {
        return in_array($role, AccessOptions::MODULE_APPS, true)
            ? ['app', $role]
            : ['app'];
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
