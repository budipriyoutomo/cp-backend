<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Support\AccessOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Role `service` dihapus dan izinnya dilebur ke `kitchen`.
 *
 * Yang diuji di sini bukan daftar konstantanya — itu cuma menyalin ulang kode —
 * tapi tiga akibat yang bisa menggigit: role lama benar-benar ditolak,
 * `kitchen` mewarisi akses baca master yang dulu dipegang `service`, dan baris
 * lama di DB tidak ditinggalkan dengan role yang tak lagi lolos middleware.
 */
class ServiceRoleRemovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secret123',
            'role'     => 'admin',
            // `/users` dijaga `module:admin` sejak modul ditegakkan di server.
            'module_app' => ['admin'],
        ]);
    }

    public function test_service_is_no_longer_a_valid_role(): void
    {
        $this->assertNotContains('service', AccessOptions::ROLES);
    }

    public function test_creating_a_user_with_the_removed_role_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'api')->postJson('/api/users', [
            'name'     => 'Service TSM',
            'email'    => 'service@example.com',
            'password' => 'secret123',
            'role'     => 'service',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    /**
     * Modul dan role adalah dua sumbu terpisah. Menghapus role `service` tidak
     * boleh ikut menutup modulnya — `app/kitchen/layout.tsx` masih menerimanya.
     */
    public function test_service_is_still_a_valid_module_app(): void
    {
        $this->assertContains('service', AccessOptions::MODULE_APPS);

        $this->actingAs($this->admin(), 'api')->postJson('/api/users', [
            'name'       => 'Service TSM',
            'email'      => 'service@example.com',
            'password'   => 'secret123',
            'role'       => 'kitchen',
            'module_app' => ['service'],
        ])->assertCreated();

        $user = User::where('email', 'service@example.com')->firstOrFail();
        $this->assertSame(['service'], $user->module_app);
    }

    /**
     * Akses baca master dulu milik `admin,kitchen,service`. Setelah `service`
     * hilang, `kitchen` harus tetap bisa membacanya — kalau tidak, seluruh
     * layar dapur berhenti karena `OutletProvider` gagal mengambil outlet.
     */
    public function test_kitchen_inherits_the_master_read_access_service_had(): void
    {
        $kitchen = $this->userWithRole('kitchen');

        foreach (['outlet', 'platecolor', 'menu', 'waste-reason', 'brand'] as $resource) {
            $this->actingAs($kitchen, 'api')
                ->getJson("/api/master/{$resource}")
                ->assertOk();
        }
    }

    public function test_kitchen_still_cannot_write_master_data(): void
    {
        $kitchen = $this->userWithRole('kitchen');

        $this->actingAs($kitchen, 'api')
            ->postJson('/api/master/outlet', ['code' => 'JKT', 'name' => 'Jakarta'])
            ->assertStatus(403);
    }

    /**
     * @dataProvider legacyServiceRoleShapes
     */
    public function test_migration_moves_legacy_service_rows_to_kitchen(string $stored): void
    {
        $user = User::create([
            'name'       => 'Service TSM',
            'email'      => 'servicetsm@maharasa.id',
            'password'   => 'secret123',
            'role'       => 'kitchen',
            'module_app' => ['service'],
        ]);

        // Lewat query builder, bukan model: role ini sudah tidak sah, jadi
        // menulisnya lewat jalur normal tidak mungkin lagi — dan baris seperti
        // inilah yang ditinggalkan versi sebelumnya di database produksi.
        DB::table('users')->where('id', $user->id)->update(['role' => $stored]);

        $this->runServiceRoleMigration();

        $this->assertSame('kitchen', DB::table('users')->where('id', $user->id)->value('role'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function legacyServiceRoleShapes(): array
    {
        // `RoleMiddleware::rolesOf()` memperlakukan ketiganya sebagai `service`
        // di runtime, jadi ketiganya harus ikut dipindah.
        return [
            'string polos' => ['service'],
            'json array'   => ['["service"]'],
            'json string'  => ['"service"'],
        ];
    }

    public function test_migration_leaves_other_roles_alone(): void
    {
        $manager = User::create([
            'name'     => 'Manager',
            'email'    => 'manager@example.com',
            'password' => 'secret123',
            'role'     => 'manager',
        ]);

        $this->runServiceRoleMigration();

        $this->assertSame('manager', DB::table('users')->where('id', $manager->id)->value('role'));
    }

    private function runServiceRoleMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_19_000000_migrate_service_role_to_kitchen.php'
        );

        $migration->up();
    }
}
