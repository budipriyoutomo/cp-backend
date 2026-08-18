<?php

namespace Tests\Feature\Master;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandTest extends TestCase
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
            'module_app' => ['admin'],
        ]);
    }

    public function test_admin_can_create_brand(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin, 'api')->postJson('/api/master/brand', [
            'code'        => 'MHR',
            'name'        => 'Maharasa',
            'description' => 'Brand utama',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.code', 'MHR')
            ->assertJsonPath('data.name', 'Maharasa');

        $this->assertDatabaseHas('brands', ['code' => 'MHR', 'name' => 'Maharasa']);
    }

    public function test_create_validation_requires_code_and_name(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->postJson('/api/master/brand', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $admin = $this->user('admin');
        Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);

        $this->actingAs($admin, 'api')->postJson('/api/master/brand', [
            'code' => 'MHR',
            'name' => 'Maharasa Dua',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_update_allows_keeping_its_own_code(): void
    {
        $admin = $this->user('admin');
        $brand = Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);

        $this->actingAs($admin, 'api')->putJson("/api/master/brand/{$brand->id}", [
            'code' => 'MHR',
            'name' => 'Maharasa Group',
        ])->assertOk()->assertJsonPath('data.name', 'Maharasa Group');

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'Maharasa Group']);
    }

    public function test_update_still_rejects_a_code_taken_by_another_brand(): void
    {
        $admin = $this->user('admin');
        Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);
        $other = Brand::create(['code' => 'KTR', 'name' => 'Katsuri']);

        $this->actingAs($admin, 'api')->putJson("/api/master/brand/{$other->id}", [
            'code' => 'MHR',
            'name' => 'Katsuri',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_admin_can_delete_brand_and_it_is_deactivated(): void
    {
        $admin = $this->user('admin');
        $brand = Brand::create(['code' => 'KTR', 'name' => 'Katsuri']);

        $this->actingAs($admin, 'api')->deleteJson("/api/master/brand/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
        // BaseService::delete() menurunkan is_active sebelum soft delete.
        $this->assertSame(0, (int) Brand::withTrashed()->find($brand->id)->is_active);
    }

    public function test_show_returns_a_single_brand(): void
    {
        $admin = $this->user('admin');
        $brand = Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);

        $this->actingAs($admin, 'api')->getJson("/api/master/brand/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'MHR');
    }

    public function test_show_returns_404_for_an_unknown_id(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->getJson('/api/master/brand/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_index_can_filter_by_code(): void
    {
        $admin = $this->user('admin');
        Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);
        Brand::create(['code' => 'KTR', 'name' => 'Katsuri']);

        $this->actingAs($admin, 'api')->getJson('/api/master/brand?code=KTR&per_page=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'KTR');
    }

    public function test_index_supports_per_page_all(): void
    {
        $admin = $this->user('admin');
        Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);
        Brand::create(['code' => 'KTR', 'name' => 'Katsuri']);

        $this->actingAs($admin, 'api')->getJson('/api/master/brand?per_page=all')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_non_admin_can_list_but_cannot_create(): void
    {
        $kitchen = $this->user('kitchen');
        Brand::create(['code' => 'MHR', 'name' => 'Maharasa']);

        // Dapur perlu baca brand untuk menyaring menu.
        $this->actingAs($kitchen, 'api')->getJson('/api/master/brand')
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->actingAs($kitchen, 'api')->postJson('/api/master/brand', [
            'code' => 'KTR',
            'name' => 'Katsuri',
        ])->assertStatus(403)->assertJsonPath('message', 'Unauthorized access');
    }

    public function test_guest_cannot_access_brands(): void
    {
        $this->getJson('/api/master/brand')->assertStatus(401);
        $this->postJson('/api/master/brand', ['code' => 'X', 'name' => 'X'])->assertStatus(401);
    }
}
