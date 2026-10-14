<?php

namespace Tests\Feature\Master;

use App\Models\WasteCategory;
use App\Models\WasteReason;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Master kategori waste. Yang paling dijaga: `code` adalah kunci di BI, jadi
 * ia tidak boleh berubah setelah dipakai, dan kategori yang masih ditunjuk
 * reason tidak boleh hilang.
 */
class WasteCategoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const URL = '/api/master/waste-category';

    private function category(array $overrides = []): WasteCategory
    {
        return WasteCategory::create(array_merge([
            'code'          => 'HANDLING',
            'category_name' => 'Handling',
            'is_active'     => true,
        ], $overrides));
    }

    public function test_the_default_category_and_system_reasons_exist_after_migrating(): void
    {
        $default = WasteCategory::where('code', WasteCategory::DEFAULT_CODE)->first();

        $this->assertNotNull($default);
        $this->assertSame('Default Waste', $default->category_name);

        foreach (array_keys(WasteReason::SYSTEM_REASONS) as $name) {
            $this->assertDatabaseHas('waste_reasons', [
                'reason_name'       => $name,
                'is_system'         => true,
                'waste_category_id' => $default->id,
            ]);
        }
    }

    public function test_admin_can_create_a_category_and_the_code_is_uppercased(): void
    {
        $this->actingAsRole('admin');

        $this->postJson(self::URL, [
            'code'          => 'shelf_life',
            'category_name' => 'Shelf Life',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SHELF_LIFE')
            ->assertJsonPath('data.category_name', 'Shelf Life')
            ->assertJsonPath('data.reasons_count', 0);

        $this->assertDatabaseHas('waste_categories', ['code' => 'SHELF_LIFE']);
    }

    public function test_code_must_be_unique_regardless_of_case(): void
    {
        $this->actingAsRole('admin');
        $this->category();

        $this->postJson(self::URL, ['code' => 'handling', 'category_name' => 'Lagi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_code_shape_is_restricted_and_uncategorized_is_reserved(): void
    {
        $this->actingAsRole('admin');

        $this->postJson(self::URL, ['code' => 'ada spasi', 'category_name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson(self::URL, ['code' => WasteCategory::UNCATEGORIZED_CODE, 'category_name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_index_lists_categories_with_their_reason_count(): void
    {
        $this->actingAsRole('admin');
        $category = $this->category();
        WasteReason::create(['reason_name' => 'Jatuh', 'waste_category_id' => $category->id]);

        $rows = collect($this->getJson(self::URL . '?per_page=all')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(1, $rows['HANDLING']['reasons_count']);
        $this->assertArrayHasKey(WasteCategory::DEFAULT_CODE, $rows->all());
    }

    public function test_name_can_change_freely_but_code_is_frozen_once_used(): void
    {
        $this->actingAsRole('admin');
        $category = $this->category();

        // Belum dipakai: kode masih boleh dibetulkan.
        $this->putJson(self::URL . "/{$category->id}", ['code' => 'HANDLE', 'category_name' => 'Handling'])
            ->assertOk()
            ->assertJsonPath('data.code', 'HANDLE');

        WasteReason::create(['reason_name' => 'Jatuh', 'waste_category_id' => $category->id]);

        $this->putJson(self::URL . "/{$category->id}", ['code' => 'OTHER', 'category_name' => 'Handling'])
            ->assertStatus(422);

        $this->putJson(self::URL . "/{$category->id}", ['code' => 'HANDLE', 'category_name' => 'Penanganan'])
            ->assertOk()
            ->assertJsonPath('data.category_name', 'Penanganan');

        $this->assertDatabaseHas('waste_categories', ['id' => $category->id, 'code' => 'HANDLE']);
    }

    public function test_code_is_frozen_when_only_waste_records_point_at_it(): void
    {
        $this->actingAsRole('admin');
        $category = $this->category();
        $outlet   = $this->createOutlet();
        $menu     = $this->createMenu();

        WasteRecord::create([
            'menu_id'           => $menu->id,
            'outlet_id'         => $outlet->id,
            'plate_color'       => $menu->plate_color_id,
            'quantity'          => 1,
            'reason'            => 'Jatuh',
            'waste_category_id' => $category->id,
            'recorded_at'       => now(),
        ]);

        $this->putJson(self::URL . "/{$category->id}", ['code' => 'OTHER', 'category_name' => 'Handling'])
            ->assertStatus(422);
    }

    public function test_a_category_still_used_by_a_reason_cannot_be_deleted(): void
    {
        $this->actingAsRole('admin');
        $category = $this->category();
        WasteReason::create(['reason_name' => 'Jatuh', 'waste_category_id' => $category->id]);

        $this->deleteJson(self::URL . "/{$category->id}")->assertStatus(409);

        $this->assertNotSoftDeleted('waste_categories', ['id' => $category->id]);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $this->actingAsRole('admin');
        $category = $this->category();

        $this->deleteJson(self::URL . "/{$category->id}")->assertOk();

        $this->assertSoftDeleted('waste_categories', ['id' => $category->id]);
    }

    public function test_only_admin_may_write_but_everyone_may_read(): void
    {
        $this->actingAsRole('kitchen');

        $this->postJson(self::URL, ['code' => 'X', 'category_name' => 'X'])->assertStatus(403);
        $this->getJson(self::URL)->assertOk();
    }

    public function test_guest_cannot_access_categories(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }
}
