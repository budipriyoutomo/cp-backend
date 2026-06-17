<?php

namespace Tests\Feature\Master;

use App\Models\Menu;
use App\Models\PlateColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    public function test_admin_can_create_menu(): void
    {
        $admin = $this->userWithRole('admin');
        $color = $this->createPlateColor();

        $this->actingAs($admin, 'api')->postJson('/api/master/menu', [
            'code'           => 'M001',
            'menuname'       => 'Sushi Salmon',
            'price'          => 20000,
            'shelf_life'     => 60,
            'plate_color_id' => $color->id,
        ])->assertCreated()->assertJsonPath('data.menuname', 'Sushi Salmon');

        $this->assertDatabaseHas('menus', ['code' => 'M001', 'menuname' => 'Sushi Salmon']);
    }

    public function test_create_validates_required_fields(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/menu', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'menuname', 'price', 'plate_color_id']);
    }

    public function test_create_rejects_non_existent_plate_color(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/menu', [
            'code'           => 'M002',
            'menuname'       => 'Tuna',
            'price'          => 15000,
            'plate_color_id' => '11111111-1111-1111-1111-111111111111',
        ])->assertStatus(422)->assertJsonValidationErrors(['plate_color_id']);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $admin = $this->userWithRole('admin');
        $color = $this->createPlateColor();
        Menu::create([
            'code' => 'M001', 'menuname' => 'Existing', 'price' => 1000,
            'plate_color_id' => $color->id,
        ]);

        $this->actingAs($admin, 'api')->postJson('/api/master/menu', [
            'code'           => 'M001',
            'menuname'       => 'Another',
            'price'          => 2000,
            'plate_color_id' => $color->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_create_menu_with_image_stores_to_disk(): void
    {
        Storage::fake('s3');
        $admin = $this->userWithRole('admin');
        $color = $this->createPlateColor();

        $this->actingAs($admin, 'api')->postJson('/api/master/menu', [
            'code'           => 'M003',
            'menuname'       => 'With Image',
            'price'          => 30000,
            'plate_color_id' => $color->id,
            'image'          => UploadedFile::fake()->create('menu.jpg', 100, 'image/jpeg'),
        ])->assertCreated();

        $menu = Menu::where('code', 'M003')->first();
        $this->assertNotNull($menu->image);
        Storage::disk('s3')->assertExists($menu->image);
    }

    public function test_admin_can_update_menu(): void
    {
        $admin = $this->userWithRole('admin');
        $color = $this->createPlateColor();
        $menu  = Menu::create([
            'code' => 'M001', 'menuname' => 'Old', 'price' => 1000,
            'plate_color_id' => $color->id,
        ]);

        $this->actingAs($admin, 'api')->putJson("/api/master/menu/{$menu->id}", [
            'code'           => 'M001',
            'menuname'       => 'New Name',
            'price'          => 2500,
            'plate_color_id' => $color->id,
        ])->assertOk()->assertJsonPath('data.menuname', 'New Name');

        $this->assertDatabaseHas('menus', ['id' => $menu->id, 'menuname' => 'New Name']);
    }

    public function test_admin_can_delete_menu(): void
    {
        $admin = $this->userWithRole('admin');
        $color = $this->createPlateColor();
        $menu  = Menu::create([
            'code' => 'M001', 'menuname' => 'Old', 'price' => 1000,
            'plate_color_id' => $color->id,
        ]);

        $this->actingAs($admin, 'api')->deleteJson("/api/master/menu/{$menu->id}")
            ->assertOk();

        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
    }

    public function test_non_admin_can_list_but_cannot_create_menu(): void
    {
        $kitchen = $this->userWithRole('kitchen');
        $color   = $this->createPlateColor();
        Menu::create(['code' => 'M001', 'menuname' => 'X', 'price' => 1000, 'plate_color_id' => $color->id]);

        $this->actingAs($kitchen, 'api')->getJson('/api/master/menu')->assertOk();

        $this->actingAs($kitchen, 'api')->postJson('/api/master/menu', [
            'code' => 'M002', 'menuname' => 'Y', 'price' => 1000, 'plate_color_id' => $color->id,
        ])->assertStatus(403);
    }

    public function test_guest_cannot_access_menu(): void
    {
        $this->getJson('/api/master/menu')->assertStatus(401);
    }
}
