<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * `module_app` sekarang batas di server, bukan cuma petunjuk tampilan.
 *
 * Sebelumnya daftar modul ikut di klaim JWT tapi tidak pernah dibaca server,
 * jadi siapa pun yang memegang token bisa memanggil endpoint modul mana pun —
 * halaman yang tidak dirender bukan endpoint yang tertutup.
 */
class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    /**
     * @param  array<int, string>  $modules
     */
    private function userWith(string $role, array $modules): User
    {
        return User::create([
            'name'       => ucfirst($role),
            'email'      => $role . '+' . uniqid() . '@example.com',
            'password'   => 'secret123',
            'role'       => $role,
            'departemen' => 'Operation',
            'outlet'     => ['BDG'],
            'module_app' => $modules,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>, 4: array<int, string>}>
     */
    public static function guardedGroups(): array
    {
        // [method, uri, role, modul yang boleh, modul yang tidak boleh]
        return [
            'production' => ['get', '/api/production/items', 'kitchen', ['kitchen'], ['operation']],
            'reports'    => ['get', '/api/reports/daily-summary', 'operation', ['report'], ['kitchen']],
            'sales'      => ['get', '/api/sales', 'operation', ['operation'], ['report']],
            'closing'    => ['get', '/api/closing-reports', 'operation', ['operation'], ['production']],
            'waste'      => ['get', '/api/waste', 'production', ['production'], ['operation']],
            'users'      => ['get', '/api/users', 'admin', ['admin'], ['report']],
        ];
    }

    /**
     * @dataProvider guardedGroups
     *
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $denied
     */
    public function test_a_module_the_user_does_not_have_is_rejected(
        string $method,
        string $uri,
        string $role,
        array $allowed,
        array $denied
    ): void {
        $user = $this->userWith($role, $denied);

        $this->actingAs($user, 'api')->json(strtoupper($method), $uri)
            ->assertStatus(403)
            ->assertJsonPath('status', false);
    }

    /**
     * @dataProvider guardedGroups
     *
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $denied
     */
    public function test_the_owning_module_gets_through(
        string $method,
        string $uri,
        string $role,
        array $allowed,
        array $denied
    ): void {
        $this->createOutlet();
        $user = $this->userWith($role, $allowed);

        $response = $this->actingAs($user, 'api')->json(strtoupper($method), $uri);

        // Yang diuji gerbangnya, bukan isinya. Sebagian endpoint ini menuntut
        // query param dan menjawab 422 tanpa itu — sah, dan bukan urusan
        // middleware. Yang tidak boleh terjadi adalah 403.
        $this->assertNotSame(
            403,
            $response->status(),
            "Modul yang memiliki {$uri} justru ditolak."
        );
    }

    /** `module_app` kosong berarti tidak punya akses, bukan punya semuanya. */
    public function test_a_user_with_no_modules_is_rejected(): void
    {
        $user = $this->userWith('kitchen', []);

        $this->actingAs($user, 'api')->getJson('/api/production/items')
            ->assertStatus(403);
    }

    /** `app` modul dasar tanpa halaman — ia tidak membuka apa pun sendirian. */
    public function test_the_base_module_alone_opens_nothing(): void
    {
        $user = $this->userWith('kitchen', ['app']);

        $this->actingAs($user, 'api')->getJson('/api/production/items')
            ->assertStatus(403);
    }

    /**
     * Baca master sengaja tidak dipagari modul: `OutletProvider` memanggilnya
     * di layout setiap modul, jadi memagarinya mematikan semua layar sekaligus.
     */
    public function test_master_reads_stay_open_to_every_module(): void
    {
        $this->createOutlet();

        foreach ([['kitchen'], ['operation'], ['production'], ['report']] as $modules) {
            $user = $this->userWith('kitchen', $modules);

            $this->actingAs($user, 'api')->getJson('/api/master/outlet')->assertOk();
            $this->actingAs($user, 'api')->getJson('/api/master/platecolor')->assertOk();
        }
    }

    /** Tapi menulisnya tetap milik modul admin — dan role admin. */
    public function test_master_writes_still_need_the_admin_module(): void
    {
        $admin = $this->userWith('admin', ['report']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/master/outlet', ['code' => 'JKT', 'name' => 'Jakarta'])
            ->assertStatus(403);
    }

    /**
     * Import backdate hidup di prefix `production` tapi milik modul `admin`.
     * Kalau ia dinested di grup production, kedua gerbang modul harus lolos
     * sekaligus — dan tidak ada user yang bisa memenuhi keduanya.
     */
    public function test_backdate_import_belongs_to_the_admin_module_despite_its_prefix(): void
    {
        $this->createOutlet();
        $admin = $this->userWith('admin', ['admin']);

        // Bukan 403: ia lolos gerbang, lalu gagal validasi seperti seharusnya.
        $this->actingAs($admin, 'api')
            ->postJson('/api/production/import-backdate/preview', [])
            ->assertStatus(422);
    }

    public function test_backdate_import_is_closed_to_the_production_module(): void
    {
        $user = $this->userWith('admin', ['production']);

        $this->actingAs($user, 'api')
            ->postJson('/api/production/import-backdate/preview', [])
            ->assertStatus(403);
    }

    /** Role `admin` tidak melewati gerbang modul — beda dengan `outlet.access`. */
    public function test_the_admin_role_does_not_bypass_the_module_gate(): void
    {
        $user = $this->userWith('admin', ['admin']);

        $this->actingAs($user, 'api')->getJson('/api/sales')
            ->assertStatus(403);
    }
}
