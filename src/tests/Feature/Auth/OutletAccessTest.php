<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Batas outlet dulu hanya dijaga frontend. Test ini mengunci versi servernya:
 * token yang sah tidak lagi cukup untuk membaca outlet orang lain.
 */
class OutletAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private function kitchenUserFor(array $codes): User
    {
        $user = $this->userWithRole('kitchen');
        $user->update(['outlet' => $codes]);

        $this->actingAs($user, 'api');

        return $user;
    }

    public function test_a_user_may_read_master_data_of_their_own_outlet(): void
    {
        $outlet = $this->createOutlet();
        $this->createMenu();
        $this->kitchenUserFor(['BDG']);

        $this->getJson('/api/master/menu?outlet_id=' . $outlet->id)
            ->assertOk();
    }

    public function test_outlet_codes_are_compared_case_insensitively(): void
    {
        $outlet = $this->createOutlet();
        $this->createMenu();
        // Frontend menormalkan kode sebelum membandingkan; server harus sama,
        // kalau tidak dapur melihat outlet yang tidak bisa dibukanya.
        $this->kitchenUserFor([' bdg ']);

        $this->getJson('/api/master/menu?outlet_id=' . $outlet->id)
            ->assertOk();
    }

    public function test_a_user_may_not_read_master_data_of_another_outlet(): void
    {
        $this->createOutlet();
        $other = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $this->kitchenUserFor(['BDG']);

        $this->getJson('/api/master/menu?outlet_id=' . $other->id)
            ->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Outlet ini tidak termasuk akses Anda');
    }

    public function test_a_user_may_not_produce_into_another_outlet(): void
    {
        $this->createOutlet();
        $other = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu  = $this->createMenu();
        $this->kitchenUserFor(['BDG']);

        // outletId di body, bukan query string.
        $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 1,
            'outletId' => $other->id,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('production_items', ['outlet_id' => $other->id]);
    }

    public function test_a_user_without_any_outlet_is_shut_out(): void
    {
        $outlet = $this->createOutlet();
        $this->createMenu();
        // module_app kosong berarti tidak punya akses, dan outlet kosong sama.
        // Gagal-tertutup, bukan gagal-terbuka.
        $this->kitchenUserFor([]);

        $this->getJson('/api/master/menu?outlet_id=' . $outlet->id)
            ->assertStatus(403);
    }

    public function test_admin_still_reaches_every_outlet(): void
    {
        $this->createOutlet();
        $other = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $this->createMenu();
        $this->actingAsRole('admin');

        $this->getJson('/api/master/menu?outlet_id=' . $other->id)
            ->assertOk();
    }

    public function test_a_request_without_an_outlet_is_left_alone(): void
    {
        $this->createOutlet();
        $this->createMenu();
        $this->kitchenUserFor(['BDG']);

        // Layar master admin memang lintas outlet; yang menjaganya `role:`,
        // bukan middleware ini.
        $this->getJson('/api/master/menu')->assertOk();
    }

    public function test_an_unknown_outlet_is_still_a_404_not_a_403(): void
    {
        $this->createOutlet();
        $this->kitchenUserFor(['BDG']);

        // Izin tidak boleh menelan error "outlet tidak ditemukan" milik service.
        $this->getJson('/api/master/menu?outlet_id=' . fake()->uuid())
            ->assertStatus(404);
    }

    public function test_a_malformed_outlet_id_does_not_become_a_500(): void
    {
        $this->createOutlet();
        $this->kitchenUserFor(['BDG']);

        $this->getJson('/api/master/menu?outlet_id=bukan-uuid')
            ->assertStatus(404);
    }
}
