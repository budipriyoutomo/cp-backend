<?php

namespace Tests\Feature\Production;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\ProductionItem;
use App\Models\ProductionStatusCorrection;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\WasteReason;
use App\Models\WasteRecord;
use App\Services\Production\ProductionItemService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Koreksi Status Produksi (sold ⇄ waste).
 *
 * Yang paling dijaga di sini adalah hal yang kalau salah tidak memunculkan
 * error apa pun: stempel yang pindah hari, satu piring yang terhitung sold
 * sekaligus waste, waste_records yang tertinggal, dan snapshot Sales Input
 * yang diam-diam tetap memakai angka lama.
 */
class ProductionStatusCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const SUMMARY_URL = '/api/production/corrections/summary';
    private const PREVIEW_URL = '/api/production/corrections/preview';
    private const URL         = '/api/production/corrections';

    private Outlet $outlet;
    private Menu $menu;
    private WasteReason $wasteReason;
    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');

        $this->outlet      = $this->createOutlet();
        $this->menu        = $this->createMenu(null, ['code' => 'SUS-001', 'menuname' => 'Salmon Nigiri']);
        $this->wasteReason = WasteReason::create(['reason_name' => 'Rusak', 'is_active' => true]);
        $this->day         = today()->subDay();
    }

    /**
     * Piring yang sudah ditutup kemarin. `sold_at`/`wasted_at` sengaja jam
     * 21:00 — beda dengan `produced_at` — supaya terlihat stempel mana yang
     * dibawa koreksi.
     */
    private function seedClosed(int $count, string $status, ?Menu $menu = null): void
    {
        $producedAt = $this->day->copy()->setTime(10, 0);
        $closedAt   = $this->day->copy()->setTime(21, 0);

        for ($i = 0; $i < $count; $i++) {
            $item = $this->createProductionItem($this->outlet, $menu ?? $this->menu, [
                'produced_at'  => $producedAt,
                'expires_at'   => $producedAt->copy()->addHour(),
                'belt_status'  => 'expired',
                'final_status' => $status,
                'sold_at'      => $status === 'sold' ? $closedAt : null,
                'wasted_at'    => $status === 'waste' ? $closedAt : null,
            ]);

            if ($status === 'waste') {
                WasteRecord::create([
                    'production_item_id' => $item->id,
                    'menu_id'            => $item->menu_id,
                    'plate_color'        => $item->plate_color,
                    'quantity'           => 1,
                    'reason'             => 'Expired',
                    'recorded_at'        => $closedAt,
                    'outlet_id'          => $this->outlet->id,
                ]);
            }
        }
    }

    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'outletId'      => $this->outlet->id,
            'date'          => $this->day->toDateString(),
            'reason'        => 'Close Day menandai semua sold padahal sebagian dibuang',
            'wasteReasonId' => $this->wasteReason->id,
            'items'         => $items,
        ], $overrides);
    }

    private function line(string $from, string $to, int $quantity, ?Menu $menu = null): array
    {
        return [
            'menuId'   => ($menu ?? $this->menu)->id,
            'from'     => $from,
            'to'       => $to,
            'quantity' => $quantity,
        ];
    }

    private function countStatus(string $status): int
    {
        return ProductionItem::where('final_status', $status)->count();
    }

    /*
    |--------------------------------------------------------------------------
    | SUMMARY & PREVIEW
    |--------------------------------------------------------------------------
    */

    public function test_summary_counts_finalised_plates_per_menu_and_reports_the_lock(): void
    {
        $this->seedClosed(5, 'sold');
        $this->seedClosed(2, 'waste');
        // Piring belum final tidak ikut dihitung — koreksi bukan jalan pintas finalisasi.
        $this->createProductionItem($this->outlet, $this->menu, ['produced_at' => $this->day->copy()->setTime(11, 0)]);

        $this->getJson(self::SUMMARY_URL . "?outletId={$this->outlet->id}&date={$this->day->toDateString()}")
            ->assertOk()
            ->assertJsonPath('data.lock.locked', false)
            ->assertJsonPath('data.unresolved', 1)
            ->assertJsonPath('data.menus.0.menuName', 'Salmon Nigiri')
            ->assertJsonPath('data.menus.0.plateColorName', 'Merah')
            ->assertJsonPath('data.menus.0.sold', 5)
            ->assertJsonPath('data.menus.0.waste', 2);
    }

    public function test_preview_shows_before_and_after_without_writing_anything(): void
    {
        $this->seedClosed(5, 'sold');
        $this->seedClosed(2, 'waste');

        $this->postJson(self::PREVIEW_URL, $this->payload([$this->line('sold', 'waste', 3)]))
            ->assertOk()
            ->assertJsonPath('data.totals.toWaste', 3)
            ->assertJsonPath('data.totals.toSold', 0)
            ->assertJsonPath('data.menus.0.soldBefore', 5)
            ->assertJsonPath('data.menus.0.soldAfter', 2)
            ->assertJsonPath('data.menus.0.wasteAfter', 5)
            ->assertJsonPath('data.plateColors.0.plateColorName', 'Merah')
            ->assertJsonPath('data.plateColors.0.wasteAfter', 5);

        $this->assertSame(5, $this->countStatus('sold'));
        $this->assertDatabaseCount('production_status_corrections', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY
    |--------------------------------------------------------------------------
    */

    public function test_sold_to_waste_moves_the_stamp_without_moving_the_day(): void
    {
        $this->seedClosed(5, 'sold');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 2)]))
            ->assertOk()
            ->assertJsonPath('message', '2 piring dikoreksi')
            ->assertJsonPath('data.totalToWaste', 2)
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonCount(2, 'data.items');

        $wasted = ProductionItem::where('final_status', 'waste')->get();

        $this->assertCount(2, $wasted);

        foreach ($wasted as $item) {
            // Stempel lama dibawa, kolom asal dikosongkan — tidak terhitung dua kali.
            $this->assertSame($this->day->copy()->setTime(21, 0)->toDateTimeString(), $item->wasted_at->toDateTimeString());
            $this->assertNull($item->sold_at);
        }

        // Get Data POS membaca per stempel penutup: masih di hari yang sama.
        $items = app(ProductionItemService::class);
        $color = $this->menu->plate_color_id;

        $this->assertSame(3, (int) $items->totalsByPlateColor($this->outlet->id, $this->day->toDateString(), 'sold_at')[$color]);
        $this->assertSame(2, (int) $items->totalsByPlateColor($this->outlet->id, $this->day->toDateString(), 'wasted_at')[$color]);

        // Analisis waste ikut, diatribusikan ke hari produksi.
        $records = WasteRecord::all();
        $this->assertCount(2, $records);
        $this->assertSame('Rusak', $records->first()->reason);
        $this->assertSame($this->day->toDateString(), $records->first()->recorded_at->toDateString());
    }

    public function test_waste_to_sold_soft_deletes_the_waste_records(): void
    {
        $this->seedClosed(3, 'waste');

        $this->postJson(self::URL, $this->payload([$this->line('waste', 'sold', 2)], ['wasteReasonId' => null]))
            ->assertOk()
            ->assertJsonPath('data.totalToSold', 2);

        $this->assertSame(2, $this->countStatus('sold'));
        $this->assertNull(ProductionItem::where('final_status', 'sold')->first()->wasted_at);
        $this->assertSame(1, WasteRecord::count());
        $this->assertSame(3, WasteRecord::withTrashed()->count());
    }

    public function test_both_directions_on_one_menu_never_pick_the_same_plate_twice(): void
    {
        $this->seedClosed(4, 'sold');
        $this->seedClosed(3, 'waste');

        $this->postJson(self::URL, $this->payload([
            $this->line('sold', 'waste', 2),
            $this->line('waste', 'sold', 3),
        ]))->assertOk();

        // 4 - 2 + 3 = 5 sold, 3 - 3 + 2 = 2 waste.
        $this->assertSame(5, $this->countStatus('sold'));
        $this->assertSame(2, $this->countStatus('waste'));
        $this->assertSame(5, \App\Models\ProductionStatusCorrectionItem::distinct('production_item_id')->count('production_item_id'));
    }

    public function test_the_audit_trail_keeps_the_old_values(): void
    {
        $this->seedClosed(1, 'sold');

        $id = $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)]))
            ->json('data.id');

        $correction = ProductionStatusCorrection::with('items')->findOrFail($id);
        $item       = $correction->items->sole();

        $this->assertSame($this->day->toDateString(), $correction->production_date->toDateString());
        $this->assertSame('sold', $item->from_status);
        $this->assertSame('waste', $item->to_status);
        $this->assertSame($this->day->copy()->setTime(21, 0)->toDateTimeString(), $item->old_sold_at->toDateTimeString());
        $this->assertNotNull($item->waste_record_id);
        $this->assertNotNull($correction->created_by);
    }

    /*
    |--------------------------------------------------------------------------
    | RULES
    |--------------------------------------------------------------------------
    */

    public function test_more_plates_than_exist_is_rejected_and_nothing_changes(): void
    {
        $this->seedClosed(2, 'sold');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 3)]))
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'hanya punya 2'))->etc());

        $this->assertSame(2, $this->countStatus('sold'));
        $this->assertDatabaseCount('production_status_corrections', 0);
    }

    public function test_correcting_to_waste_requires_a_waste_reason(): void
    {
        $this->seedClosed(2, 'sold');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)], ['wasteReasonId' => null]))
            ->assertStatus(422);

        $this->assertSame(2, $this->countStatus('sold'));
    }

    public function test_a_short_reason_is_rejected(): void
    {
        $this->seedClosed(1, 'sold');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)], ['reason' => 'salah']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_future_date_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            [$this->line('sold', 'waste', 1)],
            ['date' => today()->addDay()->toDateString()]
        ))->assertStatus(422);
    }

    /**
     * Tidak ada batas umur: selama Sales Input tanggal itu belum dibuat,
     * tanggal setua apa pun masih bisa dikoreksi.
     */
    public function test_an_old_date_without_sales_input_can_still_be_corrected(): void
    {
        $this->day = today()->subDays(400);
        $this->seedClosed(2, 'sold');

        $this->getJson(self::SUMMARY_URL . "?outletId={$this->outlet->id}&date={$this->day->toDateString()}")
            ->assertJsonPath('data.lock.locked', false);

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)]))->assertOk();

        $this->assertSame(1, $this->countStatus('waste'));
    }

    public function test_only_admins_can_correct(): void
    {
        $this->seedClosed(1, 'sold');
        $this->actingAsRole('manager');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)]))
            ->assertStatus(403);

        $this->assertSame(1, $this->countStatus('sold'));
    }

    /*
    |--------------------------------------------------------------------------
    | SALES INPUT
    |--------------------------------------------------------------------------
    */

    private function seedSales(string $status): SalesHeader
    {
        $header = SalesHeader::create([
            'outlet_id' => $this->outlet->id,
            'date'      => $this->day->toDateString(),
            'status'    => $status,
        ]);

        SalesItem::create([
            'sales_id'         => $header->id,
            'plate_color_id'   => $this->menu->plate_color_id,
            'pos_sold'         => 5,
            'production_sold'  => 5,
            'production_waste' => 0,
            'adjustment'       => 0,
            'compensation'     => 0,
            'selisih'          => 0,
        ]);

        return $header;
    }

    private function salesPayload(int $sold, int $waste): array
    {
        return [
            'outlet_id' => $this->outlet->id,
            'date'      => $this->day->toDateString(),
            'status'    => 'draft',
            'items'     => [[
                'plate_color_id'   => $this->menu->plate_color_id,
                'pos_sold'         => 5,
                'production_sold'  => $sold,
                'production_waste' => $waste,
            ]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function salesStatuses(): array
    {
        return [
            'draft'     => ['draft'],
            'submitted' => ['submitted'],
        ];
    }

    /**
     * Draft sekalipun mengunci: snapshot angka produksinya sudah tersimpan.
     *
     * @dataProvider salesStatuses
     */
    public function test_an_existing_sales_input_locks_the_day(string $status): void
    {
        $this->seedClosed(2, 'sold');
        $this->seedSales($status);

        $this->getJson(self::SUMMARY_URL . "?outletId={$this->outlet->id}&date={$this->day->toDateString()}")
            ->assertOk()
            ->assertJsonPath('data.lock.locked', true)
            ->assertJsonPath('data.lock.salesStatus', $status)
            ->assertJsonPath('data.menus.0.sold', 2);

        $this->postJson(self::PREVIEW_URL, $this->payload([$this->line('sold', 'waste', 1)]))
            ->assertStatus(422);

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)]))
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, "status {$status}"))->etc());

        $this->assertSame(2, $this->countStatus('sold'));
        $this->assertDatabaseCount('production_status_corrections', 0);
    }

    public function test_sales_input_of_another_day_does_not_lock(): void
    {
        $this->seedClosed(2, 'sold');

        SalesHeader::create([
            'outlet_id' => $this->outlet->id,
            'date'      => today()->toDateString(),
            'status'    => 'draft',
        ]);

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 1)]))->assertOk();
    }

    /**
     * Layar Sales Input yang sudah Get Data POS sebelum koreksi masih memegang
     * angka lama. Menyimpannya setelah koreksi harus ditolak, bukan diterima diam-diam.
     */
    public function test_after_a_correction_sales_must_carry_the_new_numbers(): void
    {
        $this->seedClosed(5, 'sold');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 2)]))->assertOk();

        $this->postJson('/api/sales', $this->salesPayload(5, 0))
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'Get Data POS'))->etc());
        $this->assertDatabaseCount('sales_headers', 0);

        $this->postJson('/api/sales', $this->salesPayload(3, 2))->assertStatus(201);
    }

    public function test_sales_on_an_uncorrected_day_save_as_before(): void
    {
        $this->seedClosed(5, 'sold');

        // Tidak pernah dikoreksi → tidak dibandingkan; angka apa pun dari layar diterima.
        $this->postJson('/api/sales', $this->salesPayload(4, 1))->assertStatus(201);
    }

    /*
    |--------------------------------------------------------------------------
    | BATALKAN DRAFT SALES INPUT — jalan keluar dari kunci
    |--------------------------------------------------------------------------
    */

    private function cancelSales(string $id, ?string $reason = 'Ada piring salah status, perlu dikoreksi dulu')
    {
        return $this->deleteJson("/api/sales/{$id}", ['reason' => $reason]);
    }

    public function test_cancelling_a_draft_unlocks_the_day_and_keeps_the_reason(): void
    {
        $this->seedClosed(5, 'sold');
        $sales = $this->seedSales('draft');

        $this->getJson(self::SUMMARY_URL . "?outletId={$this->outlet->id}&date={$this->day->toDateString()}")
            ->assertJsonPath('data.lock.locked', true)
            ->assertJsonPath('data.lock.salesId', $sales->id);

        $this->cancelSales($sales->id)
            ->assertOk()
            ->assertJsonPath('message', 'Draft Sales Input dibatalkan')
            ->assertJsonPath('data.deletionReason', 'Ada piring salah status, perlu dikoreksi dulu');

        $trashed = SalesHeader::withTrashed()->findOrFail($sales->id);
        $this->assertTrue($trashed->trashed());
        $this->assertSame('Ada piring salah status, perlu dikoreksi dulu', $trashed->deletion_reason);
        $this->assertNotNull($trashed->deleted_by);

        $this->getJson(self::SUMMARY_URL . "?outletId={$this->outlet->id}&date={$this->day->toDateString()}")
            ->assertJsonPath('data.lock.locked', false)
            ->assertJsonPath('data.cancelledSalesDrafts.0.id', $sales->id)
            ->assertJsonPath('data.cancelledSalesDrafts.0.cancelledByName', 'Admin')
            ->assertJsonPath('data.cancelledSalesDrafts.0.reason', 'Ada piring salah status, perlu dikoreksi dulu');

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 2)]))->assertOk();

        // Draft yang dibatalkan tidak menghalangi Sales Input baru (unique hanya baris aktif).
        $this->postJson('/api/sales', $this->salesPayload(3, 2))->assertStatus(201);
    }

    public function test_a_submitted_sales_input_cannot_be_cancelled(): void
    {
        $sales = $this->seedSales('submitted');

        $this->cancelSales($sales->id)->assertStatus(409);

        $this->assertFalse($sales->fresh()->trashed());
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $sales = $this->seedSales('draft');

        $this->cancelSales($sales->id, null)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
        $this->cancelSales($sales->id, 'salah')->assertStatus(422);

        $this->assertFalse($sales->fresh()->trashed());
    }

    public function test_only_admins_can_cancel_a_draft(): void
    {
        $sales = $this->seedSales('draft');
        $this->actingAsRole('operation');

        $this->cancelSales($sales->id)->assertStatus(403);

        $this->assertFalse($sales->fresh()->trashed());
    }

    /**
     * Sales yang pernah submitted lalu disimpan lagi sebagai draft bisa sudah
     * punya closing draft. Closing itu tidak boleh tetap menunjuk header yang
     * dibatalkan — ia harus dibangun ulang dari Sales Input yang baru.
     */
    public function test_cancelling_detaches_a_draft_closing_report(): void
    {
        $this->seedClosed(5, 'sold');
        $sales = $this->seedSales('draft');

        $report = \App\Models\ClosingReport::create([
            'outlet_id' => $this->outlet->id,
            'date'      => $this->day->toDateString(),
            'sales_id'  => $sales->id,
            'status'    => 'draft',
        ]);
        \App\Models\ClosingReportEntry::create([
            'closing_report_id' => $report->id,
            'plate_color_id'    => $this->menu->plate_color_id,
            'produced' => 5, 'sold' => 5, 'waste' => 0, 'pos_sold' => 5,
            'adjustment' => 0, 'compensation' => 0, 'selisih' => 0,
        ]);

        $this->cancelSales($sales->id)->assertOk();

        $this->assertNull($report->fresh()->sales_id);
        $this->assertSame(0, \App\Models\ClosingReportEntry::withTrashed()->count());

        $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 2)]))->assertOk();
        $this->postJson('/api/sales', array_merge($this->salesPayload(3, 2), ['status' => 'submitted']))
            ->assertStatus(201);

        $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $this->outlet->id,
            'date'            => $this->day->toDateString(),
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ])
            ->assertOk()
            ->assertJsonPath('data.totalSold', 3)
            ->assertJsonPath('data.totalWaste', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORY
    |--------------------------------------------------------------------------
    */

    public function test_history_lists_corrections_and_show_returns_the_plates(): void
    {
        $this->seedClosed(3, 'sold');

        $id = $this->postJson(self::URL, $this->payload([$this->line('sold', 'waste', 2)]))->json('data.id');

        $this->getJson(self::URL . "?outletId={$this->outlet->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.createdByName', 'Admin')
            ->assertJsonPath('data.0.wasteReasonName', 'Rusak')
            ->assertJsonPath('data.0.lines.0.menuName', 'Salmon Nigiri')
            ->assertJsonMissingPath('data.0.items');

        $this->getJson(self::URL . "/{$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }
}
