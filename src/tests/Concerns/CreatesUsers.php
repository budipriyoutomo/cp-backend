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
            // Harus cocok dengan kode outlet di SeedsProductionData::createOutlet().
            // Nilai lama 'bandung' tidak cocok kode mana pun, jadi tidak ada test
            // yang benar-benar menguji batas outlet.
            'outlet'     => ['BDG'],
            'module_app' => self::modulesForRole($role),
        ]);
    }

    /**
     * Fixture ini memegang setiap modul yang rolenya boleh pegang.
     *
     * Sengaja longgar. Sejak `module:` menegakkan modul di server, fixture yang
     * sempit membuat ratusan test bisnis gagal karena alasan yang tidak sedang
     * mereka uji — test soal perhitungan waste tidak seharusnya jatuh di gerbang
     * modul. Batas modulnya diuji di tempat yang memang untuk itu:
     * `ModuleAccessTest` dan `RouteProtectionTest`, yang menyetel `module_app`
     * eksplisit per kasus.
     *
     * Modul `admin` hanya diberikan ke role `admin`, mengikuti
     * `AccessOptions::MODULE_ROLE_REQUIREMENTS` — fixture tidak boleh membentuk
     * kombinasi yang ditolak `UserController` kalau lewat API.
     *
     * @return array<int, string>
     */
    protected static function modulesForRole(string $role): array
    {
        $modules = AccessOptions::MODULE_APPS;

        if (AccessOptions::conflictFor($role, $modules) !== null) {
            $modules = array_values(array_diff($modules, ['admin']));
        }

        return $modules;
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
