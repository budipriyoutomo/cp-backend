<?php

namespace Tests\Feature\Production;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\ProductionItem;
use App\Models\WasteCategory;
use App\Models\WasteReason;
use App\Models\WasteRecord;
use App\Services\Production\ProductionItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Snapshot reason + kategori di `waste_records`.
 *
 * Dua hal yang dijaga, keduanya tidak memunculkan error kalau salah:
 * - setiap jalur yang menulis waste mengisi snapshot-nya;
 * - layar dapur tidak berubah sama sekali — ia tetap mengirim TEKS reason,
 *   dan teks yang tidak dikenal tetap tercatat (hanya tanpa kategori).
 */
class WasteCategorySnapshotTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private Outlet $outlet;
    private Menu $menu;
    private WasteCategory $default;
    private WasteReason $expired;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');

        $this->outlet  = $this->createOutlet();
        $this->menu    = $this->createMenu(null, ['code' => 'SUS-001']);
        $this->default = WasteCategory::where('code', WasteCategory::DEFAULT_CODE)->firstOrFail();
        $this->expired = WasteReason::create(['reason_name' => 'Expired', 'waste_category_id' => $this->default->id]);
    }

    private function todayItems(int $count, bool $expired = false): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->createProductionItem($this->outlet, $this->menu, [
                'produced_at' => now()->subMinutes(30),
                'expires_at'  => $expired ? now()->subMinute() : now()->addMinutes(30),
            ])->id;
        }

        return $ids;
    }

    /**
     * Alur conveyor apa adanya: `/waste` dengan teks reason, lalu `/mark-waste`.
     */
    public function test_conveyor_waste_snapshots_the_reason_from_its_text(): void
    {
        $ids = $this->todayItems(2);

        $this->postJson('/api/production/waste', ['itemIds' => $ids, 'reason' => 'Expired'])->assertOk();
        $this->postJson('/api/production/mark-waste', ['itemIds' => $ids])->assertOk();

        $records = WasteRecord::all();
        $this->assertCount(2, $records);

        foreach ($records as $record) {
            $this->assertSame('Expired', $record->reason);
            $this->assertSame($this->expired->id, $record->waste_reason_id);
            $this->assertSame($this->default->id, $record->waste_category_id);
        }

        $this->assertSame(2, ProductionItem::where('final_status', 'waste')->count());
    }

    public function test_reason_text_is_matched_ignoring_case_and_surrounding_spaces(): void
    {
        $ids = $this->todayItems(1);

        $this->postJson('/api/production/waste', ['itemIds' => $ids, 'reason' => '  EXPIRED '])->assertOk();

        $this->assertSame($this->default->id, WasteRecord::first()->waste_category_id);
    }

    public function test_an_unknown_reason_text_is_still_recorded_without_a_category(): void
    {
        $ids = $this->todayItems(1);

        $this->postJson('/api/production/waste', ['itemIds' => $ids, 'reason' => 'Tidak ada di master'])->assertOk();

        $record = WasteRecord::first();
        $this->assertSame('Tidak ada di master', $record->reason);
        $this->assertNull($record->waste_reason_id);
        $this->assertNull($record->waste_category_id);
    }

    public function test_expired_screen_bulk_waste_snapshots_the_reason(): void
    {
        $ids = $this->todayItems(2, expired: true);

        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => $ids,
            'status'  => 'waste',
            'notes'   => 'Expired',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame(2, WasteRecord::where('waste_category_id', $this->default->id)->count());
    }

    /**
     * Inti K5: memindahkan reason ke kategori lain tidak mengubah waste yang
     * sudah tercatat — laporan hari kemarin tetap sama.
     */
    public function test_moving_a_reason_to_another_category_does_not_touch_recorded_waste(): void
    {
        $ids = $this->todayItems(1);
        $this->postJson('/api/production/waste', ['itemIds' => $ids, 'reason' => 'Expired'])->assertOk();

        $shelfLife = WasteCategory::create(['code' => 'SHELF_LIFE', 'category_name' => 'Shelf Life']);

        $this->putJson("/api/master/waste-reason/{$this->expired->id}", [
            'reason_name'       => 'Expired',
            'waste_category_id' => $shelfLife->id,
        ])->assertOk();

        $this->assertSame($this->default->id, WasteRecord::first()->waste_category_id);

        // Waste baru mengikuti mapping baru.
        $more = $this->todayItems(1);
        $this->postJson('/api/production/waste', ['itemIds' => $more, 'reason' => 'Expired'])->assertOk();

        $this->assertSame(1, WasteRecord::where('waste_category_id', $shelfLife->id)->count());
        $this->assertSame(1, WasteRecord::where('waste_category_id', $this->default->id)->count());
    }

    public function test_close_stale_snapshots_the_auto_waste_system_reason(): void
    {
        $yesterday = now()->subDay()->setTime(10, 0);
        $this->createProductionItem($this->outlet, $this->menu, [
            'produced_at' => $yesterday,
            'expires_at'  => $yesterday->copy()->addHour(),
        ]);

        app(ProductionItemService::class)->autoWasteCarryOver();

        $record = WasteRecord::firstOrFail();
        $system = WasteReason::where('reason_name', WasteReason::AUTO_WASTE)->firstOrFail();

        // Teks lama dipertahankan; kategorinya dari reason sistem.
        $this->assertStringStartsWith('Auto-waste:', $record->reason);
        $this->assertSame($system->id, $record->waste_reason_id);
        $this->assertSame($this->default->id, $record->waste_category_id);
    }

    public function test_backdate_import_snapshots_the_import_system_reason_whatever_the_notes_say(): void
    {
        $date = now()->subDay()->toDateString();

        $this->post('/api/production/import-backdate', [
            'outletId' => $this->outlet->id,
            'file'     => UploadedFile::fake()->createWithContent(
                'backdate.csv',
                "date,menu_code,quantity,final_status,notes\n{$date},SUS-001,1,waste,Jatuh saat dipindah\n"
            ),
        ])->assertOk()->assertJsonPath('data.wasteRecords', 1);

        $record = WasteRecord::firstOrFail();
        $system = WasteReason::where('reason_name', WasteReason::IMPORT_BACKDATE)->firstOrFail();

        $this->assertSame('Jatuh saat dipindah', $record->reason);
        $this->assertSame($system->id, $record->waste_reason_id);
        $this->assertSame($this->default->id, $record->waste_category_id);
    }
}
