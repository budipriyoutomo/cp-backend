<?php

namespace Tests\Feature\Production;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Mutasi berbasis `itemIds` dulu tidak membawa outlet, jadi middleware
 * `outlet.access` tidak memeriksa apa pun dan service meng-update
 * `whereIn('id', $ids)` tanpa filter outlet — user outlet BDG bisa menutup
 * piring outlet JKT.
 *
 * Sekarang: setiap item harus milik outlet request, dan user harus boleh
 * outlet itu. `outletId` masih boleh kosong selama masa transisi (antrean
 * offline lama di tablet), dan outletnya diturunkan dari item.
 */
class ProductionItemOutletScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private Outlet $bdg;
    private Outlet $jkt;
    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bdg  = $this->createOutlet();
        $this->jkt  = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $this->menu = $this->createMenu();

        // Staf dapur outlet BDG.
        $this->actingAsRole('kitchen');
    }

    /** Piring expired hari ini, supaya keempat endpoint sama-sama bisa menutupnya. */
    private function item(Outlet $outlet): ProductionItem
    {
        return $this->createProductionItem($outlet, $this->menu, [
            'produced_at' => now()->subMinutes(90),
            'expires_at'  => now()->subMinutes(30),
            'belt_status' => 'expired',
        ]);
    }

    public static function endpoints(): array
    {
        return [
            'mark-sold'    => ['/api/production/mark-sold', []],
            'mark-waste'   => ['/api/production/mark-waste', []],
            'expired/bulk' => ['/api/production/expired/bulk', ['status' => 'waste', 'notes' => 'Expired']],
            'waste'        => ['/api/production/waste', ['reason' => 'Expired']],
        ];
    }

    private function assertUntouched(ProductionItem $item): void
    {
        $this->assertDatabaseHas('production_items', ['id' => $item->id, 'final_status' => null]);
        $this->assertDatabaseMissing('waste_records', ['production_item_id' => $item->id]);
    }

    private function assertTouched(ProductionItem $item): void
    {
        $changed = ProductionItem::find($item->id)->final_status !== null
            || \App\Models\WasteRecord::where('production_item_id', $item->id)->exists();

        $this->assertTrue($changed, 'Piring outlet sendiri seharusnya diproses');
    }

    /** @dataProvider endpoints */
    public function test_items_of_another_outlet_are_refused_under_own_outlet_id(string $url, array $extra): void
    {
        $foreign = $this->item($this->jkt);

        $this->postJson($url, ['outletId' => $this->bdg->id, 'itemIds' => [$foreign->id]] + $extra)
            ->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Sebagian piring bukan milik outlet ini');

        $this->assertUntouched($foreign);
    }

    /** @dataProvider endpoints */
    public function test_a_mixed_batch_is_refused_as_a_whole(string $url, array $extra): void
    {
        $own     = $this->item($this->bdg);
        $foreign = $this->item($this->jkt);

        $this->postJson($url, ['outletId' => $this->bdg->id, 'itemIds' => [$own->id, $foreign->id]] + $extra)
            ->assertStatus(403);

        $this->assertUntouched($own);
        $this->assertUntouched($foreign);
    }

    /** @dataProvider endpoints */
    public function test_naming_another_outlet_is_refused_by_outlet_access(string $url, array $extra): void
    {
        $foreign = $this->item($this->jkt);

        $this->postJson($url, ['outletId' => $this->jkt->id, 'itemIds' => [$foreign->id]] + $extra)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Outlet ini tidak termasuk akses Anda');

        $this->assertUntouched($foreign);
    }

    /** @dataProvider endpoints */
    public function test_legacy_request_without_outlet_id_cannot_reach_another_outlet(string $url, array $extra): void
    {
        // Bentuk request lama (antrean offline sebelum deploy). Outletnya
        // diturunkan dari item, lalu izin user tetap diperiksa.
        $foreign = $this->item($this->jkt);

        $this->postJson($url, ['itemIds' => [$foreign->id]] + $extra)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Outlet ini tidak termasuk akses Anda');

        $this->assertUntouched($foreign);
    }

    /** @dataProvider endpoints */
    public function test_legacy_request_without_outlet_id_still_works_for_own_outlet(string $url, array $extra): void
    {
        $own = $this->item($this->bdg);

        $this->postJson($url, ['itemIds' => [$own->id]] + $extra)->assertOk();

        $this->assertTouched($own);
    }

    /** @dataProvider endpoints */
    public function test_own_outlet_items_are_processed(string $url, array $extra): void
    {
        $own = $this->item($this->bdg);

        $this->postJson($url, ['outletId' => $this->bdg->id, 'itemIds' => [$own->id]] + $extra)->assertOk();

        $this->assertTouched($own);
    }

    /** @dataProvider endpoints */
    public function test_admin_may_process_any_outlet(string $url, array $extra): void
    {
        $this->actingAsRole('admin');
        $foreign = $this->item($this->jkt);

        $this->postJson($url, ['outletId' => $this->jkt->id, 'itemIds' => [$foreign->id]] + $extra)->assertOk();

        $this->assertTouched($foreign);
    }

    /** @dataProvider endpoints */
    public function test_malformed_item_ids_are_422_not_500(string $url, array $extra): void
    {
        $this->postJson($url, ['outletId' => $this->bdg->id] + $extra)->assertStatus(422);
        $this->postJson($url, ['outletId' => $this->bdg->id, 'itemIds' => 'abc'] + $extra)->assertStatus(422);
        $this->postJson($url, ['outletId' => $this->bdg->id, 'itemIds' => ['abc']] + $extra)->assertStatus(422);
    }

    public function test_waste_record_is_not_written_for_an_already_finalised_plate(): void
    {
        // `/waste` dipanggil sebelum `mark-waste` (lihat useConveyorGroups
        // wasteItems di frontend), jadi piring yang sah masih final_status NULL.
        // Piring yang sudah sold/waste tidak boleh mendapat waste record kedua.
        $sold = $this->item($this->bdg);
        $sold->update(['final_status' => 'sold', 'sold_at' => now()]);

        $this->postJson('/api/production/waste', [
            'outletId' => $this->bdg->id,
            'itemIds'  => [$sold->id],
            'reason'   => 'Expired',
        ])->assertOk();

        $this->assertDatabaseMissing('waste_records', ['production_item_id' => $sold->id]);
    }
}
