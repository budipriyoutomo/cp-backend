<?php

namespace Tests\Feature\Seeders;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\User;
use App\Models\WasteReason;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\BootstrapSeeder;
use Database\Seeders\BrandSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BootstrapSeeder is what a rebuild runs against a real database, so the thing
 * that matters most is what it does NOT do: no demo transactions, no truncate,
 * and no admin account with a guessable password.
 */
class BootstrapSeederTest extends TestCase
{
    use RefreshDatabase;

    private function withBootstrapEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    private function clearBootstrapEnv(): void
    {
        foreach (['BOOTSTRAP_BRANDS', 'BOOTSTRAP_OUTLETS', 'BOOTSTRAP_ADMIN_EMAIL', 'BOOTSTRAP_ADMIN_PASSWORD', 'BOOTSTRAP_ADMIN_NAME'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        $this->clearBootstrapEnv();
        parent::tearDown();
    }

    public function test_it_seeds_the_master_data_a_rebuild_needs(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_OUTLETS'        => 'BDG:Bandung:Maharasa,JKT:Jakarta:Maharasa',
            'BOOTSTRAP_ADMIN_EMAIL'    => 'admin@example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'rahasia-panjang',
        ]);

        $this->seed(BootstrapSeeder::class);

        $this->assertGreaterThan(0, PlateColors::count());
        $this->assertGreaterThan(0, Menu::count());
        $this->assertGreaterThan(0, WasteReason::count());
        $this->assertSame(2, Outlet::count());
        $this->assertSame(1, User::count());

        // Nothing transactional is invented.
        $this->assertDatabaseCount('production_items', 0);
        $this->assertDatabaseCount('posdata', 0);
        $this->assertDatabaseCount('sales_headers', 0);
    }

    public function test_the_seeded_admin_can_actually_log_in(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_OUTLETS'        => 'BDG:Bandung:Maharasa',
            'BOOTSTRAP_ADMIN_EMAIL'    => 'admin@example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'rahasia-panjang',
        ]);

        $this->seed(BootstrapSeeder::class);

        $this->postJson('/api/login', [
            'email'    => 'admin@example.com',
            'password' => 'rahasia-panjang',
        ])->assertOk()->assertJsonPath('status', true);

        $admin = User::firstOrFail();
        $this->assertTrue(Hash::check('rahasia-panjang', $admin->password));
        $this->assertContains('BDG', $admin->outlet);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_OUTLETS'        => 'BDG:Bandung:Maharasa',
            'BOOTSTRAP_ADMIN_EMAIL'    => 'admin@example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'rahasia-panjang',
        ]);

        $this->seed(BootstrapSeeder::class);
        $colors = PlateColors::count();
        $menus  = Menu::count();

        $this->seed(BootstrapSeeder::class);

        $this->assertSame($colors, PlateColors::count());
        $this->assertSame($menus, Menu::count());
        $this->assertSame(1, Outlet::count());
        $this->assertSame(1, User::count());
    }

    public function test_the_admin_seeder_refuses_to_invent_credentials(): void
    {
        $this->clearBootstrapEnv();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BOOTSTRAP_ADMIN_EMAIL');

        $this->seed(AdminUserSeeder::class);
    }

    public function test_the_admin_seeder_rejects_a_short_password(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_ADMIN_EMAIL'    => 'admin@example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'short',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('minimal 8 karakter');

        $this->seed(AdminUserSeeder::class);
    }

    public function test_the_outlet_seeder_skips_quietly_when_unconfigured(): void
    {
        $this->clearBootstrapEnv();

        $this->seed(OutletSeeder::class);

        $this->assertSame(0, Outlet::count());
    }

    /**
     * Sengaja tidak menghitung outlet: assertion-nya harus tetap berlaku
     * berapa pun isi BOOTSTRAP_OUTLETS. Yang dijaga adalah invarian Fase 2 —
     * seeder tidak boleh meninggalkan satu pun outlet tanpa brand.
     */
    public function test_the_outlet_seeder_links_every_outlet_to_a_brand(): void
    {
        $this->withBootstrapEnv(['BOOTSTRAP_OUTLETS' => 'BDG:Bandung:Maharasa']);

        $this->seed(OutletSeeder::class);

        $this->assertGreaterThan(0, Outlet::count());
        $this->assertSame(0, Outlet::whereNull('brand_id')->count());
    }

    public function test_the_brand_seeder_reads_its_list_from_the_environment(): void
    {
        $this->withBootstrapEnv(['BOOTSTRAP_BRANDS' => 'MHR:Maharasa,KTR:Katsuri']);

        $this->seed(BrandSeeder::class);

        $this->assertSame(2, Brand::count());
        $this->assertDatabaseHas('brands', ['code' => 'MHR', 'name' => 'Maharasa']);
    }

    public function test_the_brand_seeder_keeps_ids_stable_across_runs(): void
    {
        // Mulai Fase 2 ada FK yang menunjuk ke brands. Kalau seeder menulis
        // ulang primary key saat dijalankan ulang, FK itu putus diam-diam.
        $this->withBootstrapEnv(['BOOTSTRAP_BRANDS' => 'MHR:Maharasa']);

        $this->seed(BrandSeeder::class);
        $id = Brand::firstOrFail()->id;

        $this->seed(BrandSeeder::class);

        $this->assertSame(1, Brand::count());
        $this->assertSame($id, Brand::firstOrFail()->id);
    }

    /**
     * Lubang yang gampang terlewat: BrandSeeder membuat brand, lalu
     * PlateColorSeeder dan MenuSeeder mengisi master TANPA menempelkannya ke
     * brand itu. Instalasi baru jadi punya brand tapi seluruh master-nya tanpa
     * brand — jalan, tapi bukan itu yang dimaksud.
     */
    public function test_bootstrap_attaches_the_seeded_master_data_to_the_sole_brand(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_BRANDS'         => 'MHR:Maharasa',
            'BOOTSTRAP_OUTLETS'        => 'BDG:Bandung:Maharasa',
            'BOOTSTRAP_ADMIN_EMAIL'    => 'admin@example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'rahasia-panjang',
        ]);

        $this->seed(BootstrapSeeder::class);

        $brandId = Brand::where('code', 'MHR')->value('id');

        $this->assertGreaterThan(0, PlateColors::count());
        $this->assertGreaterThan(0, Menu::count());
        $this->assertSame(0, PlateColors::whereNull('brand_id')->count());
        $this->assertSame(0, Menu::whereNull('brand_id')->count());
        $this->assertSame(0, PlateColors::where('brand_id', '!=', $brandId)->count());
    }

    public function test_menus_are_wired_to_plate_colours_of_their_own_brand(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_BRANDS'         => 'MHR:Maharasa',
            'BOOTSTRAP_ADMIN_EMAIL'    => 'admin@example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'rahasia-panjang',
        ]);

        $this->seed(BootstrapSeeder::class);

        // Menu yang menunjuk warna brand lain adalah harga yang salah, dan
        // ProductionItemService akan menolaknya saat diproduksi.
        $mismatched = Menu::query()
            ->join('plate_colors', 'plate_colors.id', '=', 'menus.plate_color_id')
            ->whereColumn('plate_colors.brand_id', '!=', 'menus.brand_id')
            ->count();

        $this->assertSame(0, $mismatched);
    }

    public function test_the_brand_seeder_skips_quietly_when_unconfigured(): void
    {
        $this->clearBootstrapEnv();

        $this->seed(BrandSeeder::class);

        $this->assertSame(0, Brand::count());
    }

    public function test_the_demo_seeder_refuses_to_run_outside_local(): void
    {
        // ProductionItemSeeder truncates production_items — running `db:seed`
        // against a real database would wipe the day's plates.
        app()['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BootstrapSeeder');

        // Invoked directly rather than through $this->seed(): the artisan
        // command asks for confirmation outside local, and prompts cannot be
        // answered in a test.
        app(DatabaseSeeder::class)->run();
    }
}
