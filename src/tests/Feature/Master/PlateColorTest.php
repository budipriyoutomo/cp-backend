<?php

namespace Tests\Feature\Master;

use App\Models\PlateColors;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlateColorTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name'       => ucfirst($role),
            'email'      => $role . '@example.com',
            'password'   => 'secret123',
            'role'       => $role,
            'departemen' => 'Operation',
            'outlet'     => ['bandung'],
            'module_app' => ['app', 'admin'],
        ]);
    }

    public function test_admin_can_create_plate_color(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin, 'api')->postJson('/api/master/platecolor', [
            'platename'       => 'Merah',
            'price'           => 15000,
            'target_foodcost' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.platename', 'Merah');

        $this->assertDatabaseHas('plate_colors', ['platename' => 'Merah']);
    }

    /**
     * Warna badge sekarang data, bukan peta nama yang dikunci mati di frontend.
     *
     * Nama bukan kunci yang sah: dua brand boleh sama-sama punya "Merah", dan
     * tidak ada yang menjamin keduanya ingin rona yang sama. `plate-colors.ts`
     * membaca field ini apa adanya, jadi namanya ikut dikunci di sini.
     */
    public function test_color_hex_round_trips_through_create_and_update(): void
    {
        $admin = $this->user('admin');

        $id = $this->actingAs($admin, 'api')->postJson('/api/master/platecolor', [
            'platename' => 'Merah',
            'price'     => 15000,
            'color_hex' => '#EF4444',
        ])
            ->assertCreated()
            ->assertJsonPath('data.color_hex', '#EF4444')
            ->json('data.id');

        $this->actingAs($admin, 'api')->putJson("/api/master/platecolor/{$id}", [
            'platename' => 'Merah',
            'price'     => 15000,
            'color_hex' => '#123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.color_hex', '#123456');

        $this->assertDatabaseHas('plate_colors', ['id' => $id, 'color_hex' => '#123456']);
    }

    /** Kosong berarti "pakai warna cadangan", bukan warna hitam. */
    public function test_color_hex_may_be_left_empty(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/platecolor', [
            'platename' => 'Motif Sakura',
            'price'     => 15000,
            'color_hex' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.color_hex', null);
    }

    public function test_color_hex_must_be_six_digit_hex(): void
    {
        $admin = $this->user('admin');

        foreach (['merah', '#FFF', 'EF4444'] as $bad) {
            $this->actingAs($admin, 'api')->postJson('/api/master/platecolor', [
                'platename' => 'Warna ' . $bad,
                'price'     => 15000,
                'color_hex' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors(['color_hex']);
        }
    }

    public function test_create_validation_requires_platename_and_price(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin, 'api')->postJson('/api/master/platecolor', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['platename', 'price']);
    }

    public function test_create_rejects_duplicate_platename(): void
    {
        $admin = $this->user('admin');
        PlateColors::create(['platename' => 'Hijau', 'price' => 1000]);

        $response = $this->actingAs($admin, 'api')->postJson('/api/master/platecolor', [
            'platename' => 'Hijau',
            'price'     => 2000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['platename']);
    }

    public function test_admin_can_update_plate_color(): void
    {
        $admin = $this->user('admin');
        $plate = PlateColors::create(['platename' => 'Biru', 'price' => 1000]);

        $response = $this->actingAs($admin, 'api')->putJson("/api/master/platecolor/{$plate->id}", [
            'platename' => 'Biru Tua',
            'price'     => 2500,
        ]);

        $response->assertOk()->assertJsonPath('data.platename', 'Biru Tua');
        $this->assertDatabaseHas('plate_colors', ['id' => $plate->id, 'platename' => 'Biru Tua']);
    }

    public function test_admin_can_delete_plate_color(): void
    {
        $admin = $this->user('admin');
        $plate = PlateColors::create(['platename' => 'Kuning', 'price' => 1000]);

        $response = $this->actingAs($admin, 'api')->deleteJson("/api/master/platecolor/{$plate->id}");

        $response->assertOk()->assertJsonPath('status', true);
        $this->assertSoftDeleted('plate_colors', ['id' => $plate->id]);
    }

    public function test_non_admin_can_list_but_cannot_create(): void
    {
        $kitchen = $this->user('kitchen');
        PlateColors::create(['platename' => 'Putih', 'price' => 1000]);

        // Read access is granted to admin/kitchen/service.
        $this->actingAs($kitchen, 'api')->getJson('/api/master/platecolor')
            ->assertOk()
            ->assertJsonPath('status', true);

        // Write access is admin-only.
        $this->actingAs($kitchen, 'api')->postJson('/api/master/platecolor', [
            'platename' => 'Hitam',
            'price'     => 1000,
        ])->assertStatus(403)->assertJsonPath('message', 'Unauthorized access');
    }

    public function test_index_supports_per_page_all(): void
    {
        $admin = $this->user('admin');
        PlateColors::create(['platename' => 'Putih', 'price' => 1000]);
        PlateColors::create(['platename' => 'Hitam', 'price' => 2000]);

        // Regression: BaseService::list() declared a LengthAwarePaginator return
        // type, so this documented option used to blow up with a 500.
        $this->actingAs($admin, 'api')->getJson('/api/master/platecolor?per_page=all')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_guest_cannot_access_plate_colors(): void
    {
        $this->getJson('/api/master/platecolor')->assertStatus(401);
        $this->postJson('/api/master/platecolor', ['platename' => 'X', 'price' => 1])->assertStatus(401);
    }
}
