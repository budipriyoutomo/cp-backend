<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ClosingReportOutbox;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use App\Services\ClosingReport\ClosingReportOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\Fakes\FakeMessagePublisher;
use Tests\TestCase;

/**
 * Submit closing report → pesan `closingreport.submitted` ke BI.
 *
 * Kontrak payload (v1) disepakati dengan BI: per menu dari sales_item_details,
 * `productionDate` dari production_items.produced_at — bukan tanggal closing.
 *
 * Broker yang mati tidak boleh menggagalkan submit: pesan menunggu di outbox
 * untuk dikirim ulang. Submit ulang atau request yang diputar ulang tidak boleh
 * menghasilkan pesan kedua.
 */
class ClosingReportPublishTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const DATE = '2026-10-02';

    private FakeMessagePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 14:00:00');
        $this->actingAsRole('admin');
        $this->publisher = FakeMessagePublisher::swap();
    }

    /**
     * Sales submitted untuk satu outlet/hari, satu plate color, dengan detail
     * per menu. Mengembalikan menu-menu yang dipakai, diindeks nama.
     *
     * @param  array<int, array{menu: Menu, sold: int, waste: int, adjustment?: int, compensation?: int}>  $lines
     */
    private function seedSubmittedSales(Outlet $outlet, array $lines): SalesHeader
    {
        $header = SalesHeader::create([
            'outlet_id' => $outlet->id,
            'date'      => self::DATE,
            'status'    => 'submitted',
        ]);

        $byColor = collect($lines)->groupBy(fn (array $line) => $line['menu']->plate_color_id);

        foreach ($byColor as $plateColorId => $colorLines) {
            $item = SalesItem::create([
                'sales_id'         => $header->id,
                'plate_color_id'   => $plateColorId,
                'pos_sold'         => $colorLines->sum('sold'),
                'production_sold'  => $colorLines->sum('sold'),
                'production_waste' => $colorLines->sum('waste'),
                'adjustment'       => $colorLines->sum(fn ($l) => $l['adjustment'] ?? 0),
                'compensation'     => $colorLines->sum(fn ($l) => $l['compensation'] ?? 0),
                'selisih'          => 0,
            ]);

            foreach ($colorLines as $line) {
                SalesItemDetail::create([
                    'sales_item_id'  => $item->id,
                    'menu_id'        => $line['menu']->id,
                    'menu_name'      => $line['menu']->menuname,
                    'total_produced' => $line['sold'] + $line['waste'],
                    'total_sold'     => $line['sold'],
                    'total_wasted'   => $line['waste'],
                    'adjustment'     => $line['adjustment'] ?? 0,
                    'compensation'   => $line['compensation'] ?? 0,
                ]);
            }
        }

        return $header;
    }

    private function submit(Outlet $outlet, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/closing-reports/submit', [
            'outletId'        => $outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ]);
    }

    /** @return array<string, array> items diindeks menuName */
    private function itemsByName(array $payload): array
    {
        return collect($payload['data']['items'])->keyBy('menuName')->all();
    }

    public function test_submit_publishes_one_message_to_the_closing_report_destination(): void
    {
        $outlet = $this->createOutlet();
        $menu = $this->createMenu(null, ['code' => 'SU-001', 'menuname' => 'Salmon Nigiri']);
        $this->seedSubmittedSales($outlet, [['menu' => $menu, 'sold' => 42, 'waste' => 3]]);

        $this->submit($outlet)->assertOk();

        $report = ClosingReport::firstOrFail();
        $this->publisher->assertPublishedCount(1);
        $message = $this->publisher->last();

        $this->assertSame('closingreport_exchange', $message['exchange']);
        $this->assertSame('closingreport.submitted', $message['routingKey']);
        $this->assertSame($report->id, $message['messageId']);

        $outbox = $report->outbox;
        $this->assertSame(ClosingReportOutbox::STATUS_PUBLISHED, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
    }

    public function test_payload_follows_the_agreed_contract(): void
    {
        $outlet = $this->createOutlet(['code' => 'MHR-01', 'name' => 'Maharasa PIK']);
        $menu = $this->createMenu(null, ['code' => 'SU-001', 'menuname' => 'Salmon Nigiri']);
        $this->createProductionItem($outlet, $menu, ['produced_at' => '2026-10-02 03:30:00']);
        $this->seedSubmittedSales($outlet, [[
            'menu' => $menu, 'sold' => 42, 'waste' => 3, 'adjustment' => 1, 'compensation' => 2,
        ]]);

        $this->submit($outlet)->assertOk();

        $report = ClosingReport::firstOrFail();
        $payload = $this->publisher->last()['payload'];

        $this->assertSame('closingreport.submitted', $payload['event']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame($report->id, $payload['messageId']);
        $this->assertSame(now()->toIso8601String(), $payload['sentAt']);

        $this->assertSame([
            'closingReportId' => $report->id,
            'date'            => self::DATE,
            'outlet'          => ['code' => 'MHR-01', 'name' => 'Maharasa PIK'],
            'brand'           => ['code' => 'MHR', 'name' => 'Maharasa'],
            'items'           => [[
                'menuId'         => $menu->id,
                'menuCode'       => 'SU-001',
                'menuName'       => 'Salmon Nigiri',
                'productionDate' => '2026-10-02',
                'sold'           => 42,
                'waste'          => 3,
                'adjustment'     => 1,
                'compensation'   => 2,
            ]],
        ], $payload['data']);
    }

    public function test_items_cover_every_menu_across_plate_colors(): void
    {
        $outlet = $this->createOutlet();
        $red = $this->createPlateColor(['platename' => 'Merah']);
        $blue = $this->createPlateColor(['platename' => 'Biru']);
        $salmon = $this->createMenu($red, ['code' => 'SU-001', 'menuname' => 'Salmon Nigiri']);
        $tuna = $this->createMenu($red, ['code' => 'SU-002', 'menuname' => 'Tuna Nigiri']);
        $ebi = $this->createMenu($blue, ['code' => 'SU-003', 'menuname' => 'Ebi Nigiri']);
        $this->seedSubmittedSales($outlet, [
            ['menu' => $salmon, 'sold' => 10, 'waste' => 1],
            ['menu' => $tuna, 'sold' => 7, 'waste' => 0],
            ['menu' => $ebi, 'sold' => 5, 'waste' => 2],
        ]);

        $this->submit($outlet)->assertOk();

        $items = $this->itemsByName($this->publisher->last()['payload']);
        $this->assertSame(['Ebi Nigiri', 'Salmon Nigiri', 'Tuna Nigiri'], array_keys($items));
        $this->assertSame(7, $items['Tuna Nigiri']['sold']);
        $this->assertSame(2, $items['Ebi Nigiri']['waste']);
    }

    public function test_menu_without_a_code_is_sent_with_a_null_code_and_its_id(): void
    {
        $outlet = $this->createOutlet();
        $menu = $this->createMenu(null, ['code' => null, 'menuname' => 'Inari']);
        $this->seedSubmittedSales($outlet, [['menu' => $menu, 'sold' => 3, 'waste' => 0]]);

        $this->submit($outlet)->assertOk();

        $item = $this->itemsByName($this->publisher->last()['payload'])['Inari'];
        $this->assertNull($item['menuCode']);
        $this->assertSame($menu->id, $item['menuId']);
    }

    public function test_production_date_comes_from_the_production_rows_of_that_menu(): void
    {
        $outlet = $this->createOutlet();
        $color = $this->createPlateColor();
        $produced = $this->createMenu($color, ['menuname' => 'Salmon Nigiri']);
        $notProduced = $this->createMenu($color, ['menuname' => 'Inari']);
        $this->createProductionItem($outlet, $produced, ['produced_at' => '2026-10-02 08:00:00']);
        $this->createProductionItem($outlet, $produced, ['produced_at' => '2026-10-02 03:00:00']);
        // Menu yang sama di outlet lain tidak boleh ikut terhitung.
        $other = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $this->createProductionItem($other, $notProduced, ['produced_at' => '2026-10-02 05:00:00']);

        $this->seedSubmittedSales($outlet, [
            ['menu' => $produced, 'sold' => 2, 'waste' => 0],
            ['menu' => $notProduced, 'sold' => 1, 'waste' => 0],
        ]);

        $this->submit($outlet)->assertOk();

        $items = $this->itemsByName($this->publisher->last()['payload']);
        $this->assertSame('2026-10-02', $items['Salmon Nigiri']['productionDate']);
        // Ada di sales tapi tanpa baris produksi di outlet ini → null, publish tetap jalan.
        $this->assertNull($items['Inari']['productionDate']);
    }

    public function test_outlet_without_a_brand_sends_a_null_brand(): void
    {
        $outlet = $this->createOutlet(['brand_id' => null, 'brand' => null]);
        $menu = $this->createMenu();
        $this->seedSubmittedSales($outlet, [['menu' => $menu, 'sold' => 1, 'waste' => 0]]);

        $this->submit($outlet)->assertOk();

        $this->assertNull($this->publisher->last()['payload']['data']['brand']);
    }

    public function test_an_unreachable_broker_does_not_fail_the_submit(): void
    {
        $this->publisher->failWith('Connection refused');
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);

        $this->submit($outlet)
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $outbox = ClosingReport::firstOrFail()->outbox;
        $this->assertSame(ClosingReportOutbox::STATUS_FAILED, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame('Connection refused', $outbox->last_error);
        $this->assertTrue($outbox->next_attempt_at->isFuture());
        // Payload sudah tersimpan, jadi retry tidak perlu membangunnya ulang.
        $this->assertSame('closingreport.submitted', $outbox->payload['event']);
    }

    public function test_submit_response_reports_the_publish_outcome(): void
    {
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);

        // Status dibaca SETELAH publish, bukan dari report yang dimuat di
        // dalam transaksi (yang masih `pending`).
        $this->submit($outlet)
            ->assertOk()
            ->assertJsonPath('data.publishStatus', 'published');
    }

    public function test_submit_response_says_failed_when_the_broker_is_down(): void
    {
        $this->publisher->failWith('Connection refused');
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);

        $this->submit($outlet)
            ->assertOk()
            ->assertJsonPath('data.publishStatus', 'failed');
    }

    public function test_show_reports_the_publish_status_and_null_for_reports_without_a_message(): void
    {
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);
        $this->submit($outlet)->assertOk();
        $published = ClosingReport::firstOrFail();

        // Report yang disubmit sebelum fitur ini ada tidak punya baris outbox.
        $legacy = ClosingReport::create([
            'outlet_id' => $this->createOutlet(['code' => 'OLD', 'name' => 'Lama'])->id,
            'date'      => '2026-09-01',
            'status'    => 'submitted',
        ]);

        $this->getJson("/api/closing-reports/{$published->id}")
            ->assertOk()
            ->assertJsonPath('data.publishStatus', 'published');

        $this->getJson("/api/closing-reports/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('data.publishStatus', null);
    }

    public function test_a_rejected_resubmit_does_not_queue_a_second_message(): void
    {
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);

        $this->submit($outlet)->assertOk();
        $this->submit($outlet)->assertStatus(409);

        $this->publisher->assertPublishedCount(1);
        $this->assertSame(1, ClosingReportOutbox::count());
    }

    public function test_a_replayed_request_does_not_publish_again(): void
    {
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);

        $this->submit($outlet, ['X-Client-Request-Id' => 'tablet-req-1'])->assertOk();
        $this->submit($outlet, ['X-Client-Request-Id' => 'tablet-req-1'])->assertOk();

        $this->publisher->assertPublishedCount(1);
    }

    public function test_a_failed_submit_publishes_nothing(): void
    {
        // Sales belum submitted → submit ditolak dan di-rollback.
        $outlet = $this->createOutlet();
        SalesHeader::create(['outlet_id' => $outlet->id, 'date' => self::DATE, 'status' => 'draft']);

        $this->submit($outlet)->assertStatus(422);

        $this->publisher->assertNothingPublished();
        $this->assertSame(0, ClosingReportOutbox::count());
    }

    public function test_publishing_an_already_published_row_is_a_no_op(): void
    {
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);
        $this->submit($outlet)->assertOk();

        $outbox = ClosingReportOutbox::firstOrFail();
        app(ClosingReportOutboxService::class)->publish($outbox);

        $this->publisher->assertPublishedCount(1);
    }

    public function test_retry_delay_grows_and_is_capped(): void
    {
        $service = app(ClosingReportOutboxService::class);

        $this->assertSame(
            [1, 2, 4, 8, 16, 32, 60, 60],
            array_map(fn (int $attempt) => $service->retryDelayMinutes($attempt), range(1, 8))
        );
    }

    public function test_sent_at_reflects_the_actual_send_time_on_a_retry(): void
    {
        $this->publisher->failWith('Connection refused');
        $outlet = $this->createOutlet();
        $this->seedSubmittedSales($outlet, [['menu' => $this->createMenu(), 'sold' => 1, 'waste' => 0]]);
        $this->submit($outlet)->assertOk();

        Carbon::setTestNow('2026-10-03 01:00:00');
        $this->publisher->failWith(null);
        app(ClosingReportOutboxService::class)->publish(ClosingReportOutbox::firstOrFail());

        $payload = $this->publisher->last()['payload'];
        $this->assertSame(now()->toIso8601String(), $payload['sentAt']);
        // Hari laporannya tetap hari produksi, bukan hari dikirim.
        $this->assertSame(self::DATE, $payload['data']['date']);
    }
}
