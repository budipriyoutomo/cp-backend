<?php

namespace Tests\Feature\Seeders;

use App\Models\Menu;
use App\Models\PlateColors;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PlateColorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The menu master decides two things that leak straight into the day's numbers:
 * which plate color a dish is priced at, and how long a plate stays fresh.
 */
class MenuSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedMenus(): void
    {
        $this->seed(PlateColorSeeder::class);
        $this->seed(MenuSeeder::class);
    }

    public function test_it_seeds_every_menu_with_a_plate_color(): void
    {
        $this->seedMenus();

        $this->assertSame(72, Menu::count());
        $this->assertSame(0, Menu::whereNull('plate_color_id')->count());
    }

    public function test_every_menu_price_matches_its_plate_color_price(): void
    {
        // Harga menu dan harga plate color harus sama — POS menjual per warna,
        // laporan memakai harga menu. Beda sedikit = rekonsiliasi meleset.
        $this->seedMenus();

        $plates = PlateColors::pluck('price', 'id');

        foreach (Menu::all() as $menu) {
            $this->assertEqualsWithDelta(
                (float) $plates[$menu->plate_color_id],
                (float) $menu->price,
                0.01,
                "Harga {$menu->menuname} ({$menu->code}) tidak sama dengan harga plate color-nya."
            );
        }
    }

    public function test_shelf_life_is_set_so_produce_does_not_fall_back_to_sixty_minutes(): void
    {
        $this->seedMenus();

        $this->assertSame(0, Menu::whereNull('shelf_life')->count());
        $this->assertSame(72, Menu::where('shelf_life', 120)->count());
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $this->seedMenus();
        $this->seed(MenuSeeder::class);

        $this->assertSame(72, Menu::count());
    }

    public function test_rerunning_does_not_wipe_an_uploaded_image(): void
    {
        // Versi lama menulis `'image' => null` tiap run, jadi foto yang diunggah
        // lewat halaman admin hilang begitu seeder dijalankan lagi.
        $this->seedMenus();

        $menu        = Menu::where('code', '10020')->firstOrFail();
        $menu->image = 'menus/foto-baru.jpg';
        $menu->save();

        $this->seed(MenuSeeder::class);

        $this->assertSame('menus/foto-baru.jpg', $menu->fresh()->image);
    }

    public function test_it_skips_menus_whose_plate_color_is_missing_instead_of_crashing(): void
    {
        // Tanpa PlateColorSeeder, `plate_color_id` NOT NULL akan menolak semuanya.
        $this->seed(MenuSeeder::class);

        $this->assertSame(0, Menu::count());
    }

    public function test_the_seeded_names_match_the_production_dump(): void
    {
        $this->seedMenus();

        $expected = [
            '16057' => 'Shima Aji Aburi Sushi',
            '16058' => 'Shima Aji Sushi',
            '16026' => 'Aburi Salmon Belly Sushi',
            '10008' => 'Chuka Chinmi',
            '18002' => 'Soft Shel Crab Maki',
        ];

        foreach ($expected as $code => $name) {
            $this->assertSame($name, Menu::where('code', $code)->value('menuname'));
        }
    }
}
