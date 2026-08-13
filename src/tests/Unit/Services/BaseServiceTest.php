<?php

namespace Tests\Unit\Services;

use App\Models\PlateColors;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * BaseService drives every list endpoint in the API, so its query-string
 * contract (include / search / filter / sort / per_page) is worth pinning down
 * once here instead of re-testing it per module.
 *
 * Fixture model is PlateColors — a plain BaseModel with an `is_active` column,
 * which also exercises the soft-delete path in delete().
 */
class BaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BaseService
    {
        return new TestablePlateColorService();
    }

    private function request(array $query = []): Request
    {
        return Request::create('/plate-colors', 'GET', $query);
    }

    private function seedColors(): void
    {
        PlateColors::create(['platename' => 'Merah',  'price' => 15000, 'is_active' => true]);
        PlateColors::create(['platename' => 'Biru',   'price' => 25000, 'is_active' => true]);
        PlateColors::create(['platename' => 'Kuning', 'price' => 20000, 'is_active' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | list() / all()
    |--------------------------------------------------------------------------
    */

    public function test_list_paginates_with_the_default_page_size(): void
    {
        $this->seedColors();

        $result = $this->service()->list($this->request());

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(15, $result->perPage());
        $this->assertSame(3, $result->total());
    }

    public function test_list_honours_an_explicit_per_page(): void
    {
        $this->seedColors();

        $result = $this->service()->list($this->request(['per_page' => 2]));

        $this->assertSame(2, $result->perPage());
        $this->assertSame(3, $result->total());
        $this->assertCount(2, $result->items());
    }

    public function test_list_returns_an_unpaginated_collection_for_per_page_all(): void
    {
        $this->seedColors();

        $result = $this->service()->list($this->request(['per_page' => 'all']));

        // Regression: the declared return type used to be LengthAwarePaginator
        // only, so this documented option threw a TypeError (HTTP 500).
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(3, $result);
    }

    public function test_all_returns_every_row_without_pagination(): void
    {
        $this->seedColors();

        $result = $this->service()->all($this->request());

        $this->assertCount(3, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | search / filter
    |--------------------------------------------------------------------------
    */

    public function test_search_matches_partially_across_searchable_fields(): void
    {
        $this->seedColors();

        $result = $this->service()->all($this->request(['search' => 'era']));

        $this->assertCount(1, $result);
        $this->assertSame('Merah', $result->first()->platename);
    }

    public function test_search_returns_nothing_when_no_field_matches(): void
    {
        $this->seedColors();

        $this->assertCount(0, $this->service()->all($this->request(['search' => 'Ungu'])));
    }

    public function test_exact_filter_applies_only_to_searchable_fields(): void
    {
        $this->seedColors();

        $matched = $this->service()->all($this->request(['platename' => 'Biru']));
        $this->assertCount(1, $matched);
        $this->assertSame('Biru', $matched->first()->platename);

        // `description` is not declared searchable, so it must be ignored
        // rather than silently narrowing (or blowing up) the query.
        $ignored = $this->service()->all($this->request(['description' => 'anything']));
        $this->assertCount(3, $ignored);
    }

    /*
    |--------------------------------------------------------------------------
    | sort
    |--------------------------------------------------------------------------
    */

    public function test_sort_ascending_and_descending(): void
    {
        $this->seedColors();

        $asc = $this->service()->all($this->request(['sort' => 'price']))->pluck('platename')->all();
        $this->assertSame(['Merah', 'Kuning', 'Biru'], $asc);

        $desc = $this->service()->all($this->request(['sort' => '-price']))->pluck('platename')->all();
        $this->assertSame(['Biru', 'Kuning', 'Merah'], $desc);
    }

    public function test_sort_ignores_fields_that_are_not_whitelisted(): void
    {
        $this->seedColors();

        // `description` is not sortable — the clause must be dropped, not applied.
        $result = $this->service()->all($this->request(['sort' => '-description,price']))
            ->pluck('platename')->all();

        $this->assertSame(['Merah', 'Kuning', 'Biru'], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | find / create / update / delete
    |--------------------------------------------------------------------------
    */

    public function test_find_returns_the_model_or_null(): void
    {
        $color = PlateColors::create(['platename' => 'Merah', 'price' => 15000, 'is_active' => true]);

        $this->assertSame($color->id, $this->service()->find($color->id)?->id);
        $this->assertNull($this->service()->find('11111111-1111-1111-1111-111111111111'));
    }

    public function test_create_persists_and_assigns_a_uuid(): void
    {
        $created = $this->service()->create([
            'platename' => 'Hijau',
            'price'     => 18000,
            'is_active' => true,
        ]);

        $this->assertNotEmpty($created->id);
        $this->assertFalse($created->incrementing);
        $this->assertDatabaseHas('plate_colors', ['id' => $created->id, 'platename' => 'Hijau']);
    }

    public function test_update_writes_the_changes(): void
    {
        $color = PlateColors::create(['platename' => 'Merah', 'price' => 15000, 'is_active' => true]);

        $updated = $this->service()->update($color->id, ['platename' => 'Merah Tua']);

        $this->assertSame('Merah Tua', $updated->platename);
        $this->assertDatabaseHas('plate_colors', ['id' => $color->id, 'platename' => 'Merah Tua']);
    }

    public function test_update_throws_when_the_record_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Data not found');

        $this->service()->update('11111111-1111-1111-1111-111111111111', ['platename' => 'X']);
    }

    public function test_delete_deactivates_then_soft_deletes(): void
    {
        $color = PlateColors::create(['platename' => 'Merah', 'price' => 15000, 'is_active' => true]);

        $this->service()->delete($color->id);

        // Row still there, but flagged inactive and soft deleted.
        $this->assertSoftDeleted('plate_colors', ['id' => $color->id]);
        $this->assertDatabaseHas('plate_colors', ['id' => $color->id, 'is_active' => 0]);

        // And it is gone from every subsequent read.
        $this->assertNull($this->service()->find($color->id));
        $this->assertCount(0, $this->service()->all($this->request()));
    }

    public function test_delete_throws_when_the_record_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Data not found');

        $this->service()->delete('11111111-1111-1111-1111-111111111111');
    }
}

/**
 * Minimal concrete service. PlateColorService itself declares no
 * $searchable/$sortable, so the query-string behaviour needs a fixture that does.
 */
class TestablePlateColorService extends BaseService
{
    protected string $model = PlateColors::class;

    protected array $searchable = ['platename'];
    protected array $sortable   = ['platename', 'price'];
}
