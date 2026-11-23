<?php

namespace Tests\Feature\ClosingReport;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\ProductionItem;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * `closingreport.submitted` v3: v2 + harga satuan piring dan nilai rupiah
 * per sold / waste / adjustment / compensation.
 *
 * Yang dijaga:
 * - harga = harga plate color menu (unit harga domain), bukan `menus.price`;
 * - v3 tetap membawa seluruh isi v2 (`wasteByCategory`);
 * - warna yang sudah di-soft-delete tetap memberi harga;
 * - v1/v2 tidak berubah bentuk.
 */
class ClosingReportPayloadV3Test extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const DATE = '2026-10-02';

    private FakeMessagePublisher $publisher;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 14:00:00');
        $this->actingAsRole('admin');
        $this->publisher = FakeMessagePublisher::swap();

        $this->outlet = $this->createOutlet();

        config(['rabbitmq.closing_report.payload_version' => '3']); // dari .env selalu string
    }

    private function plate(Menu $menu, string $status): ProductionItem
    {
        return $this->createProductionItem($this->outlet, $menu, [
            'produced_at'  => self::DATE . ' 03:00:00',
            'final_status' => $status,
            $status === 'sold' ? 'sold_at' : 'wasted_at' => self::DATE . ' 05:00:00',
        ]);
    }

    private function submittedSales(): SalesHeader
    {
        return SalesHeader::create([
            'outlet_id' => $this->outlet->id,
            'date'      => self::DATE,
            'status'    => 'submitted',
        ]);
    }

    private function detail(SalesHeader $header, Menu $menu, int $sold, int $waste, int $adjustment, int $compensation): void
    {
        $item = SalesItem::create([
            'sales_id'         => $header->id,
            'plate_color_id'   => $menu->plate_color_id,
            'pos_sold'         => $sold,
            'production_sold'  => $sold,
            'production_waste' => $waste,
            'adjustment'       => $adjustment,
            'compensation'     => $compensation,
            'selisih'          => 0,
        ]);

        SalesItemDetail::create([
            'sales_item_id'  => $item->id,
            'menu_id'        => $menu->id,
            'menu_name'      => $menu->menuname,
            'total_produced' => $sold + $waste,
            'total_sold'     => $sold,
            'total_wasted'   => $waste,
            'adjustment'     => $adjustment,
            'compensation'   => $compensation,
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

    public function test_v3_sends_plate_color_price_and_amount_per_quantity(): void
    {
        $merah = $this->createPlateColor(['platename' => 'Merah', 'price' => 15000]);
        // Harga menu sengaja beda: yang dikirim harus harga warna.
        $salmon = $this->createMenu($merah, ['menuname' => 'Salmon Nigiri', 'price' => 20000]);

        $this->plate($salmon, 'sold');
        $header = $this->submittedSales();
        $this->detail($header, $salmon, sold: 10, waste: 2, adjustment: 1, compensation: 3);

        $payload = $this->submit();
        $item = $payload['data']['items'][0];

        $this->assertSame(3, $payload['version']);
        $this->assertEquals(15000, $item['unitPrice']);
        $this->assertEquals(150000, $item['soldAmount']);
        $this->assertEquals(30000, $item['wasteAmount']);
        $this->assertEquals(15000, $item['adjustmentAmount']);
        $this->assertEquals(45000, $item['compensationAmount']);
    }

    public function test_v3_keeps_the_v2_waste_breakdown(): void
    {
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->plate($salmon, 'waste');
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertArrayHasKey('wasteByCategory', $item);
        $this->assertSame(1, array_sum(array_column($item['wasteByCategory'], 'quantity')));
        $this->assertEquals(15000, $item['wasteAmount']);
    }

    public function test_zero_quantities_give_zero_amounts(): void
    {
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->plate($salmon, 'sold');
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertEquals(15000, $item['soldAmount']);
        $this->assertEquals(0, $item['wasteAmount']);
        $this->assertEquals(0, $item['adjustmentAmount']);
        $this->assertEquals(0, $item['compensationAmount']);
    }

    public function test_decimal_prices_are_kept_to_two_places(): void
    {
        $hijau = $this->createPlateColor(['platename' => 'Hijau', 'price' => 12500.5]);
        $salmon = $this->createMenu($hijau, ['menuname' => 'Salmon Nigiri']);
        $this->plate($salmon, 'sold');
        $this->plate($salmon, 'sold');
        $this->plate($salmon, 'sold');
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertEquals(12500.5, $item['unitPrice']);
        $this->assertEquals(37501.5, $item['soldAmount']);
    }

    /** Warna yang sudah dihapus tetap memberi harga — laporan lama tidak boleh kehilangan nilai. */
    public function test_a_soft_deleted_plate_color_still_prices_the_menu(): void
    {
        $merah = $this->createPlateColor(['platename' => 'Merah', 'price' => 15000]);
        $salmon = $this->createMenu($merah, ['menuname' => 'Salmon Nigiri']);
        $this->plate($salmon, 'sold');
        $merah->delete();
        $this->submittedSales();

        $item = $this->submit()['data']['items'][0];

        $this->assertEquals(15000, $item['unitPrice']);
    }

    /**
     * Yang terlihat di layar harus sama dengan yang dikirim. Skenarionya
     * sengaja memuat semua titik yang dulu menyimpang: harga menu ≠ harga
     * warna, harga berdesimal, dan rincian Sales Input yang berbeda dari
     * hitungan produksi.
     */
    public function test_screen_shows_exactly_what_is_sent_to_bi(): void
    {
        $merah = $this->createPlateColor(['platename' => 'Merah', 'price' => 12500.5]);
        $salmon = $this->createMenu($merah, ['menuname' => 'Salmon Nigiri', 'price' => 20000]);
        $tuna = $this->createMenu($this->createPlateColor(['platename' => 'Biru', 'price' => 9000]), ['menuname' => 'Tuna Nigiri', 'price' => 11000]);

        $this->plate($salmon, 'sold');
        $this->plate($salmon, 'sold');
        $this->plate($tuna, 'waste');
        $header = $this->submittedSales();
        // Rincian Salmon dibekukan dengan angka yang beda dari produksi.
        $this->detail($header, $salmon, sold: 5, waste: 1, adjustment: 2, compensation: 1);

        $sent = collect($this->submit()['data']['items'])->keyBy('menuId');

        $screens = [
            'closing report' => $this->getJson('/api/closing-reports/' . $this->publisher->last()['payload']['messageId'])
                ->assertOk()->json('data.menuEntries'),
            'closing data'   => $this->getJson('/api/closing-reports/data?outletId=' . $this->outlet->id . '&date=' . self::DATE)
                ->assertOk()->json('data.entries'),
        ];

        foreach ($screens as $screen => $entries) {
            $this->assertCount($sent->count(), $entries, $screen);

            foreach ($entries as $entry) {
                $item = $sent->get($entry['menuId']);

                $this->assertSame($item['menuName'], $entry['menuName'], $screen);
                $this->assertEquals($item['unitPrice'], $entry['sellingPrice'], $screen);
                $this->assertSame($item['sold'], $entry['sold'], $screen);
                $this->assertSame($item['waste'], $entry['waste'], $screen);
                $this->assertSame($item['adjustment'], $entry['adjustment'], $screen);
                $this->assertSame($item['compensation'], $entry['compensation'], $screen);
                // Frontend menampilkan kompensasi Rp = compensation × sellingPrice.
                $this->assertEquals($item['compensationAmount'], $entry['compensation'] * $entry['sellingPrice'], $screen);
            }
        }

        $this->assertEquals(12500.5, $sent[$salmon->id]['unitPrice']);
        $this->assertSame(5, $sent[$salmon->id]['sold']);
    }

    public function test_v1_and_v2_carry_no_prices(): void
    {
        $salmon = $this->createMenu(null, ['menuname' => 'Salmon Nigiri']);
        $this->plate($salmon, 'sold');
        $this->submittedSales();

        config(['rabbitmq.closing_report.payload_version' => '2']);
        $item = $this->submit()['data']['items'][0];

        $this->assertArrayNotHasKey('unitPrice', $item);
        $this->assertArrayNotHasKey('soldAmount', $item);
    }
}
