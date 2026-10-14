<?php

namespace Tests\Feature\Master;

use App\Models\WasteCategory;
use App\Models\WasteReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class WasteReasonTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    private function defaultCategory(): WasteCategory
    {
        return WasteCategory::where('code', WasteCategory::DEFAULT_CODE)->firstOrFail();
    }

    public function test_admin_can_create_waste_reason(): void
    {
        $admin    = $this->userWithRole('admin');
        $category = $this->defaultCategory();

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name'       => 'Basi',
            'description'       => 'Kadaluarsa',
            'waste_category_id' => $category->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.reason_name', 'Basi')
            ->assertJsonPath('data.waste_category_id', $category->id)
            ->assertJsonPath('data.waste_category_code', WasteCategory::DEFAULT_CODE)
            ->assertJsonPath('data.waste_category_name', 'Default Waste');

        $this->assertDatabaseHas('waste_reasons', ['reason_name' => 'Basi', 'waste_category_id' => $category->id]);
    }

    public function test_a_new_reason_must_have_an_active_category(): void
    {
        $admin    = $this->userWithRole('admin');
        $inactive = WasteCategory::create(['code' => 'OLD', 'category_name' => 'Lama', 'is_active' => false]);

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name' => 'Basi',
        ])->assertStatus(422)->assertJsonValidationErrors(['waste_category_id']);

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name'       => 'Basi',
            'waste_category_id' => $inactive->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['waste_category_id']);

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name'       => 'Basi',
            'waste_category_id' => 'bukan-uuid',
        ])->assertStatus(422)->assertJsonValidationErrors(['waste_category_id']);
    }

    /**
     * Reason lama boleh masih tanpa kategori. Update tidak memaksanya, dan
     * update tanpa field kategori tidak menghapus kategori yang sudah ada.
     */
    public function test_update_keeps_the_category_when_it_is_not_sent(): void
    {
        $admin    = $this->userWithRole('admin');
        $category = $this->defaultCategory();
        $reason   = WasteReason::create(['reason_name' => 'Basi', 'waste_category_id' => $category->id]);
        $legacy   = WasteReason::create(['reason_name' => 'Lama']);

        $this->actingAs($admin, 'api')->putJson("/api/master/waste-reason/{$reason->id}", [
            'reason_name' => 'Basi Sekali',
        ])->assertOk()->assertJsonPath('data.waste_category_id', $category->id);

        $this->actingAs($admin, 'api')->putJson("/api/master/waste-reason/{$legacy->id}", [
            'reason_name' => 'Lama',
        ])->assertOk()->assertJsonPath('data.waste_category_id', null);
    }

    /**
     * Dropdown dapur memanggil index tanpa parameter, jadi reason sistem tidak
     * boleh muncul di sana. Layar admin memintanya dengan `with_system=1`.
     */
    public function test_system_reasons_are_hidden_unless_asked_for(): void
    {
        $admin = $this->userWithRole('admin');
        WasteReason::create(['reason_name' => 'Basi']);

        $names = collect($this->actingAs($admin, 'api')->getJson('/api/master/waste-reason?per_page=all')->json('data'))
            ->pluck('reason_name');

        $this->assertContains('Basi', $names);
        $this->assertNotContains(WasteReason::AUTO_WASTE, $names);
        $this->assertNotContains(WasteReason::IMPORT_BACKDATE, $names);

        $names = collect($this->actingAs($admin, 'api')->getJson('/api/master/waste-reason?per_page=all&with_system=1')->json('data'))
            ->pluck('reason_name');

        $this->assertContains(WasteReason::AUTO_WASTE, $names);
        $this->assertContains(WasteReason::IMPORT_BACKDATE, $names);
    }

    public function test_a_system_reason_cannot_be_renamed_or_deleted_but_can_be_recategorised(): void
    {
        $admin  = $this->userWithRole('admin');
        $system = WasteReason::where('reason_name', WasteReason::AUTO_WASTE)->firstOrFail();
        $other  = WasteCategory::create(['code' => 'SHELF_LIFE', 'category_name' => 'Shelf Life']);

        $this->actingAs($admin, 'api')->putJson("/api/master/waste-reason/{$system->id}", [
            'reason_name' => 'Otomatis',
        ])->assertStatus(422);

        $this->actingAs($admin, 'api')->deleteJson("/api/master/waste-reason/{$system->id}")
            ->assertStatus(409);

        $this->actingAs($admin, 'api')->putJson("/api/master/waste-reason/{$system->id}", [
            'reason_name'       => WasteReason::AUTO_WASTE,
            'waste_category_id' => $other->id,
        ])->assertOk()->assertJsonPath('data.waste_category_code', 'SHELF_LIFE');

        $this->assertNotSoftDeleted('waste_reasons', ['id' => $system->id]);
    }

    public function test_is_system_cannot_be_set_through_the_api(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name'       => 'Palsu',
            'waste_category_id' => $this->defaultCategory()->id,
            'is_system'         => true,
        ])->assertCreated();

        $this->assertDatabaseHas('waste_reasons', ['reason_name' => 'Palsu', 'is_system' => false]);
    }

    public function test_create_requires_reason_name(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason_name']);
    }

    public function test_create_rejects_duplicate_reason_name(): void
    {
        $admin = $this->userWithRole('admin');
        WasteReason::create(['reason_name' => 'Basi']);

        $this->actingAs($admin, 'api')->postJson('/api/master/waste-reason', [
            'reason_name'       => 'Basi',
            'waste_category_id' => $this->defaultCategory()->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason_name']);
    }

    public function test_admin_can_update_waste_reason(): void
    {
        $admin  = $this->userWithRole('admin');
        $reason = WasteReason::create(['reason_name' => 'Basi']);

        $this->actingAs($admin, 'api')->putJson("/api/master/waste-reason/{$reason->id}", [
            'reason_name' => 'Kadaluarsa',
        ])->assertOk()->assertJsonPath('data.reason_name', 'Kadaluarsa');

        $this->assertDatabaseHas('waste_reasons', ['id' => $reason->id, 'reason_name' => 'Kadaluarsa']);
    }

    public function test_admin_can_delete_waste_reason(): void
    {
        $admin  = $this->userWithRole('admin');
        $reason = WasteReason::create(['reason_name' => 'Basi']);

        $this->actingAs($admin, 'api')->deleteJson("/api/master/waste-reason/{$reason->id}")
            ->assertOk();

        $this->assertSoftDeleted('waste_reasons', ['id' => $reason->id]);
    }

    public function test_non_admin_cannot_create_waste_reason(): void
    {
        $kitchen = $this->userWithRole('kitchen');

        $this->actingAs($kitchen, 'api')->postJson('/api/master/waste-reason', [
            'reason_name'       => 'Jatuh',
            'waste_category_id' => $this->defaultCategory()->id,
        ])->assertStatus(403);
    }

    public function test_guest_cannot_access_waste_reasons(): void
    {
        $this->getJson('/api/master/waste-reason')->assertStatus(401);
    }
}
