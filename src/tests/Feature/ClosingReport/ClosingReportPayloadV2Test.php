<?php

namespace Tests\Feature\ClosingReport;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\ProductionItem;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use App\Models\WasteCategory;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * `closingreport.submitted` v2: rincian waste per kategori di setiap item.
 *
 * Disepakati dengan BI (docs/jawaban-bi-closing-report-v2.md). Yang dijaga:
 * - v2 hanya aktif lewat config — consumer BI di produksi baru menerima v1,
 *   dan pesan v2 yang ditolak tidak kembali ke outbox kita (masuk DLQ mereka);
 * - total `wasteByCategory[].quantity` selalu sama dengan `waste`, selisihnya
 *   ke UNCATEGORIZED;
 * - kategori dibaca dari snapshot di waste_records, bukan mapping reason terkini;
 * - urutannya deterministik (quantity turun, lalu categoryCode naik) supaya
 *   `--rebuild` menghasilkan isi yang identik.
 */
class ClosingReportPayloadV2Test extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const DATE = '2026-10-02';

    private FakeMessagePublisher $publisher;
    private Outlet $outlet;
    private WasteCategory $default;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 14:00:00');
        $this->actingAsRole('admin');
        $this->publisher = FakeMessagePublisher::swap();

        $this->outlet  = $this->createOutlet();
        $this->default = WasteCategory::where('code', WasteCategory::DEFAULT_CODE)->firstOrFail();

        config(['rabbitmq.closing_report.payload_version' => 2]);
    }

    private function category(string $code, string $name): WasteCategory
    {
        return WasteCategory::create(['code' => $code, 'category_name' => $name]);
    }

    /** Piring waste hari itu, dengan satu waste_record berkategori (atau tanpa record). */
    private function wasted(Menu $menu, ?WasteCategory $category, bool $withRecord = true): ProductionItem
    {
        $item = $this->createProductionItem($this->outlet, $menu, [
            'produced_at'  => self::DATE . ' 03:00:00',
            'final_status' => 'waste',
            'wasted_at'    => self::DATE . ' 05:00:00',
        ]);

        if ($withRecord) {
            $this->record($item, $category);
        }

        return $item;
    }

    private function record(ProductionItem $item, ?WasteCategory $category, string $at = self::DATE . ' 05:00:00'): WasteRecord
    {
        return WasteRecord::create([
            'production_item_id' => $item->id,
            'menu_id'            => $item->menu_id,
            'outlet_id'          => $item->outlet_id,
            'plate_color'        => $item->plate_color,
            'quantity'           => 1,
            'reason'             => 'Expired',
            'waste_category_id'  => $category?->id,
            'recorded_at'        => $at,
        ]);
    }

    private function sold(Menu $menu): ProductionItem
    {
        return $this->createProductionItem($this->outlet, $menu, [
            'produced_at'  => self::DATE . ' 03:00:00',
            'final_status' => 'sold',
            'sold_at'      => self::DATE . ' 04:00:00',
        ]);
    }

    /** Sales submitted tanpa rincian menu — angka item dari production_items. */
    private function submittedSales(): SalesHeader
    {
        return SalesHeader::create([
            'outlet_id' => $this->outlet->id,
            'date'      => self::DATE,
            'status'    => 'submitted',
        ]);
    }

    /** Rincian Sales Input untuk satu menu: `waste` dibekukan di sana dan menang. */
    private function detail(SalesHeader $header, Menu $menu, int $sold, int $waste): void
    {
        $item = SalesItem::create([
            'sales_id'         => $header->id,
            'plate_color_id'   => $menu->plate_color_id,
            'pos_sold'         => $sold,
            'production_sold'  => $sold,
            'production_waste' => $waste,
            'adjustment'       => 0,
            'compensation'     => 0,
            'selisih'          => 0,
        ]);

        SalesItemDetail::create([
            'sales_item_id'  => $item->id,
            'menu_id'        => $menu->id,
            'menu_name'      => $menu->menuname,
            'total_produced' => $sold + $waste,
            'total_sold'     => $sold,
            'total_wasted'   => $waste,
            'adjustment'     => 0,
            'compensation'   => 0,
        ]);
    }

    private function submit(): array
    {
        $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $this->outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ])->assertOk();

        return $this->publisher->last()['payload'];
    }

    /** @return array<string, array> items diindeks menuName */
    private function items(array $payload): array
    {
        return collect($payload['data']['items'])->keyBy('menuName')->all();
    }

    public function test_default_config_still_sends_v1_without_the_breakdown(): void
    {
        config(['rabbitmq.closing_report.payload_version' => null]);
        $menu = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->wasted($menu, $this->default);
        $this->submittedSales();

        $payload = $this->submit();

        $this->assertSame(1, $payload['version']);
        $this->assertArrayNotHasKey('wasteByCategory', $payload['data']['items'][0]);
    }

    /** Nilai yang tidak dikenal jatuh ke v1 — satu-satunya versi yang pasti diterima BI. */
    public function test_an_unknown_version_falls_back_to_v1(): void
    {
        config(['rabbitmq.closing_report.payload_version' => '3']);
        $menu = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->wasted($menu, $this->default);
        $this->submittedSales();

        $payload = $this->submit();

        $this->assertSame(1, $payload['version']);
        $this->assertArrayNotHasKey('wasteByCategory', $payload['data']['items'][0]);
    }

    public function test_v2_breaks_waste_down_by_snapshot_category(): void
    {
        config(['rabbitmq.closing_report.payload_version' => '2']); // dari .env selalu string
        $handling = $this->category('HANDLING', 'Kesalahan Handling');
        $salmon = $this->createMenu(null, ['code' => 'SU-001', 'menuname' => 'Salmon Nigiri']);

        $this->wasted($salmon, $this->default);
        $this->wasted($salmon, $this->default);
        $this->wasted($salmon, $handling);
        $this->sold($salmon);
        $this->submittedSales();

        $payload = $this->submit();
        $item = $payload['data']['items'][0];

        $this->assertSame(2, $payload['version']);
        $this->assertSame(3, $item['waste']);
        $this->assertSame([
            ['categoryCode' => 'DEFAULT_WASTE', 'categoryName' => 'Default Waste', 'quantity' => 2],
            ['categoryCode' => 'HANDLING', 'categoryName' => 'Kesalahan Handling', 'quantity' => 1],
        ], $item['wasteByCategory']);
    }

    public function test_menu_without_waste_gets_an_empty_array(): void
    {
        $tamago = $this->createMenu(null, ['menuname' => 'Tamago']);
        $this->sold($tamago);
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertSame(0, $item['waste']);
        $this->assertSame([], $item['wasteByCategory']);
    }

    /**
     * Waste tanpa kategori: record tanpa snapshot (data sebelum fitur), atau
     * piring waste tanpa record sama sekali. Keduanya tetap terhitung.
     */
    public function test_waste_without_a_category_goes_to_uncategorized(): void
    {
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->wasted($salmon, $this->default);
        $this->wasted($salmon, null);
        $this->wasted($salmon, null, withRecord: false);
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertSame([
            ['categoryCode' => 'UNCATEGORIZED', 'categoryName' => 'Belum dikategorikan', 'quantity' => 2],
            ['categoryCode' => 'DEFAULT_WASTE', 'categoryName' => 'Default Waste', 'quantity' => 1],
        ], $item['wasteByCategory']);
    }

    public function test_equal_quantities_are_ordered_by_category_code(): void
    {
        $zeta = $this->category('ZETA', 'Zeta');
        $alpha = $this->category('ALPHA', 'Alpha');
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->wasted($salmon, $zeta);
        $this->wasted($salmon, $alpha);
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertSame(['ALPHA', 'ZETA'], array_column($item['wasteByCategory'], 'categoryCode'));
    }

    /**
     * `recordFromItems()` tidak mencegah duplikat, dan koreksi status
     * menghapus (soft delete) record piring yang ternyata terjual. Satu piring
     * = satu unit waste, kategorinya dari record hidup yang tercatat pertama.
     */
    public function test_each_wasted_plate_counts_once_from_its_first_live_record(): void
    {
        $handling = $this->category('HANDLING', 'Kesalahan Handling');
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);

        $plate = $this->wasted($salmon, $handling);
        $this->record($plate, $this->default, self::DATE . ' 06:00:00');

        $other = $this->wasted($salmon, null, withRecord: false);
        $this->record($other, $handling)->delete();
        $this->record($other, $this->default, self::DATE . ' 07:00:00');

        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertSame(2, $item['waste']);
        $this->assertSame([
            ['categoryCode' => 'DEFAULT_WASTE', 'categoryName' => 'Default Waste', 'quantity' => 1],
            ['categoryCode' => 'HANDLING', 'categoryName' => 'Kesalahan Handling', 'quantity' => 1],
        ], $item['wasteByCategory']);
    }

    public function test_waste_of_other_days_and_outlets_is_not_counted(): void
    {
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->wasted($salmon, $this->default);

        $yesterday = $this->createProductionItem($this->outlet, $salmon, [
            'produced_at' => '2026-10-01 03:00:00', 'final_status' => 'waste', 'wasted_at' => '2026-10-01 05:00:00',
        ]);
        $this->record($yesterday, $this->default);

        $elsewhere = $this->createProductionItem($this->createOutlet(['code' => 'OTHER']), $salmon, [
            'produced_at' => self::DATE . ' 03:00:00', 'final_status' => 'waste', 'wasted_at' => self::DATE . ' 05:00:00',
        ]);
        $this->record($elsewhere, $this->default);

        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertSame([
            ['categoryCode' => 'DEFAULT_WASTE', 'categoryName' => 'Default Waste', 'quantity' => 1],
        ], $item['wasteByCategory']);
    }

    /**
     * `waste` dari rincian Sales Input menang atas production_items. Kalau
     * keduanya tidak sepakat, rinciannya disesuaikan ke `waste` — jumlah yang
     * cocok adalah jaminan kita ke BI.
     */
    public function test_breakdown_is_reconciled_to_the_frozen_sales_waste(): void
    {
        $handling = $this->category('HANDLING', 'Kesalahan Handling');
        $salmon = $this->createMenu($this->createPlateColor(['platename' => 'Merah']), ['menuname' => 'Salmon Nigiri']);
        $tuna = $this->createMenu($this->createPlateColor(['platename' => 'Biru']), ['menuname' => 'Tuna Nigiri']);

        // Salmon: produksi mencatat 1 waste, rincian Sales Input 3.
        $this->wasted($salmon, $this->default);
        // Tuna: produksi mencatat 3 waste, rincian Sales Input 1.
        $this->wasted($tuna, $this->default);
        $this->wasted($tuna, $handling);
        $this->wasted($tuna, null);

        $header = $this->submittedSales();
        $this->detail($header, $salmon, 0, 3);
        $this->detail($header, $tuna, 0, 1);

        $items = $this->items($this->submit());

        $this->assertSame([
            ['categoryCode' => 'UNCATEGORIZED', 'categoryName' => 'Belum dikategorikan', 'quantity' => 2],
            ['categoryCode' => 'DEFAULT_WASTE', 'categoryName' => 'Default Waste', 'quantity' => 1],
        ], $items['Salmon Nigiri']['wasteByCategory']);

        // Dipangkas dari UNCATEGORIZED dulu, lalu dari kategori terkecil.
        $this->assertSame([
            ['categoryCode' => 'DEFAULT_WASTE', 'categoryName' => 'Default Waste', 'quantity' => 1],
        ], $items['Tuna Nigiri']['wasteByCategory']);

        foreach ($items as $item) {
            $this->assertSame($item['waste'], array_sum(array_column($item['wasteByCategory'], 'quantity')));
        }
    }

    /** Nama tampilan diambil saat pesan disusun; kodenya yang stabil. */
    public function test_category_name_is_read_when_the_message_is_built(): void
    {
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->wasted($salmon, $this->default);
        $this->default->update(['category_name' => 'Waste Umum']);
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertSame('DEFAULT_WASTE', $item['wasteByCategory'][0]['categoryCode']);
        $this->assertSame('Waste Umum', $item['wasteByCategory'][0]['categoryName']);
    }
}
