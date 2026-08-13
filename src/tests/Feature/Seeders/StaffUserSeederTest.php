<?php

namespace Tests\Feature\Seeders;

use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\StaffUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff accounts carry credentials written into the repo, so the two things
 * worth pinning are that they actually work (password login and PIN login) and
 * that re-running never overwrites a PIN somebody has already rotated.
 */
class StaffUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_one_kitchen_and_one_service_account_per_outlet(): void
    {
        $this->seed(StaffUserSeeder::class);

        $this->assertSame(10, User::count());
        $this->assertSame(5, User::where('role', 'kitchen')->count());
        $this->assertSame(5, User::where('role', 'service')->count());

        foreach (['TSM', 'JWB', 'PVJ', 'FMB', 'P23'] as $code) {
            $kitchen = User::where('email', 'kitchen' . strtolower($code) . '@maharasa.id')->first();

            $this->assertNotNull($kitchen, "Akun kitchen {$code} tidak dibuat.");
            $this->assertSame(['ST' . $code], $kitchen->outlet);
            $this->assertSame(['kitchen'], $kitchen->module_app);
            $this->assertSame('Operation', $kitchen->departemen);
        }

        $service = User::where('email', 'servicetsm@maharasa.id')->firstOrFail();
        $this->assertSame(['service'], $service->module_app);
    }

    public function test_the_seeded_staff_can_log_in_with_password_and_with_pin(): void
    {
        $this->seed(StaffUserSeeder::class);

        $this->postJson('/api/login', [
            'email'    => 'kitchentsm@maharasa.id',
            'password' => 'kittsm2026',
        ])->assertOk();

        $this->postJson('/api/login-pin', ['pin' => '630653'])
            ->assertOk();
    }

    public function test_pins_are_hashed_and_indexed_never_stored_in_the_clear(): void
    {
        $this->seed(StaffUserSeeder::class);

        $user = User::where('email', 'kitchentsm@maharasa.id')->firstOrFail();

        $this->assertNotSame('269301', $user->pin);
        $this->assertSame(User::pinLookup('269301'), $user->pin_lookup);
    }

    public function test_rerunning_does_not_overwrite_a_rotated_credential(): void
    {
        $this->seed(StaffUserSeeder::class);

        $user = User::where('email', 'kitchentsm@maharasa.id')->firstOrFail();
        $user->password = 'password-baru';
        $user->pin      = '111222';
        $user->save();

        $this->seed(StaffUserSeeder::class);

        $this->assertSame(10, User::count());

        $this->postJson('/api/login', [
            'email'    => 'kitchentsm@maharasa.id',
            'password' => 'password-baru',
        ])->assertOk();

        $this->postJson('/api/login-pin', ['pin' => '269301'])
            ->assertUnauthorized();
    }

    public function test_it_creates_users_even_when_the_outlets_are_not_seeded_yet(): void
    {
        $this->assertSame(0, Outlet::count());

        $this->seed(StaffUserSeeder::class);

        $this->assertSame(10, User::count());
    }

    public function test_it_refuses_to_run_in_production_by_default(): void
    {
        // Kredensialnya ada di dalam kode seeder — kalau dipakai di production
        // itu harus keputusan sadar, bukan efek samping `db:seed`.
        app()['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STAFF_SEED_ALLOW_PRODUCTION');

        app(StaffUserSeeder::class)->run();
    }
}
