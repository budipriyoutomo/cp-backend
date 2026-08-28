<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Support\AccessOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kombinasi role + module_app yang mustahil dipakai ditolak saat disimpan.
 *
 * Modul `admin` membuka layar master, users, dan import backdate — ketiganya
 * dijaga `role:admin` di server. Memberikan modul itu ke role lain menghasilkan
 * halaman yang terbuka tapi setiap aksinya 403: bukan lubang keamanan, tapi
 * kegagalan membingungkan yang tidak ada gunanya dibiarkan bisa tersimpan.
 */
class RoleModuleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'       => 'Admin',
            'email'      => 'admin@example.com',
            'password'   => 'secret123',
            'role'       => 'admin',
            'module_app' => ['admin'],
        ]);
    }

    public function test_the_admin_module_cannot_be_given_to_another_role(): void
    {
        $this->actingAs($this->admin(), 'api')->postJson('/api/users', [
            'name'       => 'Manajer',
            'email'      => 'manajer@example.com',
            'password'   => 'secret123',
            'role'       => 'manager',
            'module_app' => ['admin'],
        ])->assertStatus(422)->assertJsonValidationErrors(['module_app']);

        $this->assertDatabaseMissing('users', ['email' => 'manajer@example.com']);
    }

    public function test_the_admin_module_is_fine_for_the_admin_role(): void
    {
        $this->actingAs($this->admin(), 'api')->postJson('/api/users', [
            'name'       => 'Admin Dua',
            'email'      => 'admin2@example.com',
            'password'   => 'secret123',
            'role'       => 'admin',
            'module_app' => ['admin'],
        ])->assertCreated();
    }

    public function test_other_module_combinations_stay_free(): void
    {
        // Dua sumbu ini menjawab pertanyaan berbeda, jadi selain `admin`
        // kombinasinya sengaja tidak dibatasi.
        $this->actingAs($this->admin(), 'api')->postJson('/api/users', [
            'name'       => 'Manajer',
            'email'      => 'manajer@example.com',
            'password'   => 'secret123',
            'role'       => 'manager',
            'module_app' => ['operation', 'report'],
        ])->assertCreated();
    }

    /** Mengubah role saja tetap bisa menghasilkan kombinasi timpang. */
    public function test_update_catches_a_conflict_created_by_changing_the_role_alone(): void
    {
        $user = User::create([
            'name'       => 'Admin Lama',
            'email'      => 'lama@example.com',
            'password'   => 'secret123',
            'role'       => 'admin',
            'module_app' => ['admin'],
        ]);

        $this->actingAs($this->admin(), 'api')
            ->putJson("/api/users/{$user->id}", ['role' => 'operation'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['module_app']);

        $this->assertSame('admin', $user->refresh()->role);
    }

    /** Dan mengubah modul saja, dengan role yang tidak ikut dikirim. */
    public function test_update_catches_a_conflict_created_by_changing_the_modules_alone(): void
    {
        $user = User::create([
            'name'       => 'Operasi',
            'email'      => 'ops@example.com',
            'password'   => 'secret123',
            'role'       => 'operation',
            'module_app' => ['operation'],
        ]);

        $this->actingAs($this->admin(), 'api')
            ->putJson("/api/users/{$user->id}", ['module_app' => ['admin']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['module_app']);
    }

    public function test_the_rule_lives_in_one_place(): void
    {
        $this->assertNull(AccessOptions::conflictFor('admin', ['admin']));
        $this->assertNotNull(AccessOptions::conflictFor('kitchen', ['admin']));
        $this->assertNull(AccessOptions::conflictFor('kitchen', ['kitchen', 'service']));
    }
}
