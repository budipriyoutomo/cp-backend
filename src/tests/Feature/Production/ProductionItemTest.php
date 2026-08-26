<?php

namespace Tests\Feature\Production;

use App\Models\ProductionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class ProductionItemTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        // Every route exercised here now sits behind auth:api.
        $this->actingAsRole('admin');
    }

    public function test_produce_creates_one_item_per_quantity(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $response = $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 3,
            'outletId' => $outlet->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(3, 'data');

        $this->assertSame(3, ProductionItem::where('menu_id', $menu->id)->count());
        $this->assertDatabaseHas('production_items', [
            'menu_id'     => $menu->id,
            'outlet_id'   => $outlet->id,
            'plate_color' => $menu->plate_color_id,
            'quantity'    => 1,
        ]);
    }

    public function test_produce_validates_payload(): void
    {
        $this->postJson('/api/production/produce', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menuId', 'quantity', 'outletId']);
    }

    public function test_produce_rejects_non_existent_menu(): void
    {
        $outlet = $this->createOutlet();

        $this->postJson('/api/production/produce', [
            'menuId'   => '11111111-1111-1111-1111-111111111111',
            'quantity' => 1,
            'outletId' => $outlet->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['menuId']);
    }

    public function test_conveyor_grouped_collapses_a_production_batch_into_one_row(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        // Satu batch: produce() memakai satu `$now` untuk semua barisnya.
        $this->postJson('/api/production/produce', [
            'menuId'   => $menu->id,
            'quantity' => 5,
            'outletId' => $outlet->id,
        ])->assertOk();

        $response = $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.menuId', $menu->id)
            ->assertJsonPath('data.0.quantity', 5)
            ->assertJsonPath('data.0.beltStatus', 'fresh');

        $itemIds = $response->json('data.0.itemIds');

        // `quantity` dipakai frontend untuk mengiris `itemIds` — panjangnya
        // harus sama, kalau tidak "buang 5" akan mengirim kurang dari 5 id.
        $this->assertCount(5, $itemIds);
        $this->assertSame(
            ProductionItem::where('menu_id', $menu->id)->pluck('id')->sort()->values()->all(),
            collect($itemIds)->sort()->values()->all()
        );
    }

    public function test_conveyor_grouped_keeps_different_menus_and_times_apart(): void
    {
        $outlet = $this->createOutlet();
        $plate  = $this->createPlateColor();
        $salmon = $this->createMenu($plate);
        $tuna   = $this->createMenu($plate, ['menuname' => 'Sushi Tuna']);

        // Waktunya relatif terhadap satu `$t` yang ditangkap sekali. Memanggil
        // now() berulang kali memberi mikrodetik yang berbeda, dan dua baris
        // satu batch akan pecah jadi dua group hanya karena itu.
        $t = now();

        $earlyProduced = $t->copy()->subMinutes(20);
        $earlyExpires  = $t->copy()->addMinutes(40);
        $lateProduced  = $t->copy()->subMinutes(10);
        $lateExpires   = $t->copy()->addMinutes(50);

        // Menu sama, jam beda → dua group.
        $this->createProductionItem($outlet, $salmon, ['produced_at' => $earlyProduced, 'expires_at' => $earlyExpires]);
        $this->createProductionItem($outlet, $salmon, ['produced_at' => $earlyProduced, 'expires_at' => $earlyExpires]);
        $this->createProductionItem($outlet, $salmon, ['produced_at' => $lateProduced,  'expires_at' => $lateExpires]);

        // Jam sama dengan batch pertama, menu beda → group sendiri.
        $this->createProductionItem($outlet, $tuna, ['produced_at' => $earlyProduced, 'expires_at' => $earlyExpires]);

        $data = $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data');

        // Batch salmon berisi dua piring, sisanya satu-satu.
        $quantities = collect($data)->pluck('quantity', 'menuId');
        $this->assertSame(1, $quantities[$tuna->id]);
        $this->assertSame([2, 1], collect($data)->where('menuId', $salmon->id)->pluck('quantity')->all());

        // Urutan mengikuti expires_at menaik, sama seperti /conveyor. Dua group
        // paling awal punya expires_at identik, jadi urutan di antara keduanya
        // tidak dijamin — yang dijamin hanya batch terakhir ada di belakang.
        $this->assertStringContainsString($lateProduced->toIso8601String(), $data[2]['groupKey']);

        // groupKey harus stabil dan unik antar-poll, kalau tidak React akan
        // membongkar-pasang kartu tiap 30 detik.
        $this->assertCount(3, array_unique(array_column($data, 'groupKey')));
    }

    public function test_conveyor_grouped_shows_only_plates_still_on_the_belt(): void
    {
        $outlet = $this->createOutlet();
        $other  = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu   = $this->createMenu();

        $onBelt = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour()]);

        // Ketiganya harus jatuh di luar group: sudah lewat expired, sudah
        // difinalisasi, dan milik outlet lain.
        $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);
        $this->createProductionItem($outlet, $menu, [
            'expires_at'   => now()->addHour(),
            'final_status' => 'sold',
            'sold_at'      => now(),
        ]);
        $this->createProductionItem($other, $menu, ['expires_at' => now()->addHour()]);

        $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 1)
            ->assertJsonPath('data.0.itemIds.0', $onBelt->id);
    }

    public function test_conveyor_grouped_reports_belt_status_from_expiry_without_saving(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $item = $this->createProductionItem($outlet, $menu, [
            'expires_at'  => now()->addMinutes(5),
            'belt_status' => 'fresh',
        ]);

        $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.beltStatus', 'warning');

        $this->assertDatabaseHas('production_items', [
            'id'          => $item->id,
            'belt_status' => 'fresh',
        ]);
    }

    public function test_conveyor_grouped_drops_the_unused_system_fields(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour()]);

        $group = $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->json('data.0');

        // Enam field ini tidak pernah dibaca layar conveyor, dan dikali seribu
        // baris merekalah sebagian besar payload lamanya.
        foreach (['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'] as $field) {
            $this->assertArrayNotHasKey($field, $group);
        }
    }

    public function test_refresh_belt_status_command_updates_the_stored_column(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $expired = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute(), 'belt_status' => 'fresh']);
        $warning = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addMinutes(5), 'belt_status' => 'fresh']);
        $fresh   = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour(), 'belt_status' => 'expired']);
        $done    = $this->createProductionItem($outlet, $menu, [
            'expires_at'   => now()->subMinute(),
            'belt_status'  => 'fresh',
            'final_status' => 'sold',
            'sold_at'      => now(),
        ]);

        $this->artisan('production:refresh-belt-status')->assertSuccessful();

        $this->assertSame('expired', $expired->fresh()->belt_status);
        $this->assertSame('warning', $warning->fresh()->belt_status);
        $this->assertSame('fresh', $fresh->fresh()->belt_status);
        // Already finalised, so it is off the belt and left alone.
        $this->assertSame('fresh', $done->fresh()->belt_status);
    }

    public function test_expired_grouped_collapses_a_batch_and_excludes_the_belt(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $t        = now();
        $produced = $t->copy()->subMinutes(90);
        $expires  = $t->copy()->subMinutes(30);

        $a = $this->createProductionItem($outlet, $menu, ['produced_at' => $produced, 'expires_at' => $expires]);
        $b = $this->createProductionItem($outlet, $menu, ['produced_at' => $produced, 'expires_at' => $expires]);

        // Masih di belt — milik /conveyor-grouped, bukan halaman ini.
        $this->createProductionItem($outlet, $menu, ['expires_at' => $t->copy()->addHour()]);

        $group = $this->getJson('/api/production/expired-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(2, $group['quantity']);
        $this->assertSame('expired', $group['beltStatus']);
        // Kedua sisi diurutkan: id-nya UUID acak, jadi urutan penulisan bukan
        // urutan leksikal dan membandingkan apa adanya akan goyang.
        $this->assertSame(
            collect([$a->id, $b->id])->sort()->values()->all(),
            collect($group['itemIds'])->sort()->values()->all()
        );
    }

    public function test_expired_grouped_leaves_out_finalised_and_other_outlets(): void
    {
        $outlet = $this->createOutlet();
        $other  = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu   = $this->createMenu();

        $stillOpen = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);

        $this->createProductionItem($outlet, $menu, [
            'expires_at'   => now()->subMinute(),
            'final_status' => 'waste',
            'wasted_at'    => now(),
        ]);
        $this->createProductionItem($other, $menu, ['expires_at' => now()->subMinute()]);

        $this->getJson('/api/production/expired-grouped?outletId=' . $outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 1)
            ->assertJsonPath('data.0.itemIds.0', $stillOpen->id);
    }

    /**
     * Kedua sisi belt dibaca lewat satu definisi query (`beltQuery`), jadi yang
     * dijaga di sini adalah bahwa keduanya benar-benar saling melengkapi: tiap
     * piring hari ini yang belum difinalisasi muncul di persis satu daftar.
     */
    public function test_the_grouped_lists_partition_the_day_between_them(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $onBelt  = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour()]);
        $overdue = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);

        $ids = function (string $endpoint) use ($outlet): array {
            $data = $this->getJson("/api/production/{$endpoint}?outletId={$outlet->id}")
                ->assertOk()
                ->json('data');

            return collect($data)->pluck('itemIds')->flatten()->sort()->values()->all();
        };

        $this->assertSame([$onBelt->id], $ids('conveyor-grouped'));
        $this->assertSame([$overdue->id], $ids('expired-grouped'));
    }

    /**
     * Every tablet polls these two lists every 30 seconds, so their cost has to
     * stay flat in the number of plates on the belt.
     *
     * ProductionItemResource reads `menu->menuname` and
     * `menu->plateColor->platename`. Neither list goes through buildQuery(), so
     * the `$relations` property does not apply to them — `$this->query()` is a
     * bare newQuery(). Without an explicit `with()` each row woke two more
     * queries, and a 100-plate belt turned one GET into ~201 round trips.
     */
    public function test_belt_lists_do_not_grow_queries_with_the_number_of_plates(): void
    {
        $outlet = $this->createOutlet();

        // Distinct menu + plate color per plate: shared ones would be resolved
        // from Eloquent's identity map and hide the N+1 this test guards.
        $seedPlates = function (int $count, int $offset) use ($outlet): void {
            for ($i = $offset; $i < $offset + $count; $i++) {
                $color = $this->createPlateColor(['platename' => "Warna {$i}"]);
                $menu  = $this->createMenu($color, [
                    'code'     => "MENU{$i}",
                    'menuname' => "Sushi {$i}",
                ]);

                // One plate still on the belt, one already past due, so both
                // endpoints see a growing list.
                $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour()]);
                $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);
            }
        };

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $measure = function (string $endpoint) use ($outlet, &$queries): int {
            $queries = 0;
            $this->getJson("/api/production/{$endpoint}?outletId={$outlet->id}")->assertOk();

            return $queries;
        };

        $seedPlates(1, 0);
        $conveyorWithOne = $measure('conveyor-grouped');
        $expiredWithOne  = $measure('expired-grouped');

        $seedPlates(9, 1);
        $conveyorWithTen = $measure('conveyor-grouped');
        $expiredWithTen  = $measure('expired-grouped');

        // Sanity check: the lists really did grow, so a flat query count means
        // eager loading, not an empty response.
        //
        // Menu berbeda per piring, jadi tidak ada yang tergabung di sini — 10
        // group dari 10 piring. Yang diukur eager loading-nya, bukan grouping.
        $this->getJson('/api/production/conveyor-grouped?outletId=' . $outlet->id)->assertJsonCount(10, 'data');
        $this->getJson('/api/production/expired-grouped?outletId=' . $outlet->id)->assertJsonCount(10, 'data');

        $this->assertSame($conveyorWithOne, $conveyorWithTen);
        $this->assertSame($expiredWithOne, $expiredWithTen);
    }

    public function test_update_expired_bulk_rejects_a_status_outside_sold_and_waste(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => [$item->id],
            'status'  => 'invalid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertNull($item->fresh()->final_status);
    }

    public function test_update_expired_bulk_closes_a_whole_batch_as_sold(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $items = collect(range(1, 4))->map(fn () => $this->createProductionItem($outlet, $menu, [
            'expires_at' => now()->subMinute(),
        ]));

        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => $items->pluck('id')->all(),
            'status'  => 'sold',
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 4)
            ->assertJsonPath('data.skipped', 0);

        foreach ($items as $item) {
            $item->refresh();
            $this->assertSame('sold', $item->final_status);
            $this->assertNotNull($item->sold_at);
        }

        // Sold tidak menyentuh waste_records sama sekali.
        $this->assertDatabaseCount('waste_records', 0);
    }

    public function test_update_expired_bulk_writes_one_waste_record_per_plate(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $items = collect(range(1, 3))->map(fn () => $this->createProductionItem($outlet, $menu, [
            'expires_at' => now()->subMinute(),
        ]));

        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => $items->pluck('id')->all(),
            'status'  => 'waste',
            'notes'   => 'Jatuh di lantai',
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 3);

        $this->assertDatabaseCount('waste_records', 3);
        $this->assertDatabaseHas('waste_records', [
            'production_item_id' => $items->first()->id,
            'reason'             => 'Jatuh di lantai',
            'quantity'           => 1,
            'outlet_id'          => $outlet->id,
        ]);
    }

    /**
     * Tablet dapur dipakai bergantian dan halaman expired dibuka di beberapa
     * layar sekaligus, jadi piring yang sudah ditutup orang lain adalah kejadian
     * normal — bukan alasan membuntukan seluruh batch.
     */
    public function test_update_expired_bulk_skips_plates_someone_else_already_closed(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $open   = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);
        $taken  = $this->createProductionItem($outlet, $menu, [
            'expires_at'   => now()->subMinute(),
            'final_status' => 'sold',
            'sold_at'      => now(),
        ]);
        // Belum expired: bukan milik halaman ini, jadi ikut dilewati.
        $onBelt = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->addHour()]);

        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => [$open->id, $taken->id, $onBelt->id],
            'status'  => 'waste',
            'notes'   => 'Sisa sore',
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->assertSame('waste', $open->fresh()->final_status);
        // Yang sudah ditutup orang lain tidak ditimpa.
        $this->assertSame('sold', $taken->fresh()->final_status);
        $this->assertNull($onBelt->fresh()->final_status);

        // Dan yang paling penting: waste_records hanya untuk piring yang benar
        // benar diklaim. Mencatat tiga akan menggandakan angka waste hari itu.
        $this->assertDatabaseCount('waste_records', 1);
        $this->assertDatabaseHas('waste_records', ['production_item_id' => $open->id]);
    }

    public function test_update_expired_bulk_rejects_the_whole_request_for_a_previous_day(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $today     = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);
        $yesterday = $this->createProductionItem($outlet, $menu, [
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);

        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => [$today->id, $yesterday->id],
            'status'  => 'sold',
        ])->assertStatus(422);

        // Salah hari menggagalkan seluruh request — tidak ada yang setengah jalan.
        $this->assertNull($today->fresh()->final_status);
        $this->assertNull($yesterday->fresh()->final_status);
    }

    public function test_update_expired_bulk_validates_its_payload(): void
    {
        $this->postJson('/api/production/expired/bulk', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['itemIds', 'status']);

        // Waste tanpa alasan tidak berguna di laporan analisis waste.
        $this->postJson('/api/production/expired/bulk', [
            'itemIds' => ['11111111-1111-1111-1111-111111111111'],
            'status'  => 'waste',
        ])->assertStatus(422)->assertJsonValidationErrors(['notes']);
    }

    public function test_update_expired_bulk_is_replayed_rather_than_applied_twice(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, ['expires_at' => now()->subMinute()]);

        $payload = ['itemIds' => [$item->id], 'status' => 'waste', 'notes' => 'Kering'];
        $headers = ['X-Client-Request-Id' => 'bulk-expired-once'];

        $this->postJson('/api/production/expired/bulk', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        // Antrean offline mengirim ulang request yang sama saat koneksi kembali.
        // Middleware Idempotency harus memutar ulang responsnya, bukan menulis
        // waste_records kedua untuk piring yang sama.
        $this->postJson('/api/production/expired/bulk', $payload, $headers)
            ->assertOk()
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.updated', 1);

        $this->assertDatabaseCount('waste_records', 1);
    }

    public function test_mark_sold_updates_items(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $this->postJson('/api/production/mark-sold', ['itemIds' => [$item->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Items marked as sold');

        $item->refresh();
        $this->assertSame('sold', $item->final_status);
        $this->assertNotNull($item->sold_at);
    }

    public function test_mark_waste_updates_items(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu);

        $this->postJson('/api/production/mark-waste', ['itemIds' => [$item->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Items marked as waste');

        $item->refresh();
        $this->assertSame('waste', $item->final_status);
        $this->assertNotNull($item->wasted_at);
    }

    public function test_mark_sold_rejects_items_from_previous_day(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $item   = $this->createProductionItem($outlet, $menu, [
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);

        $this->postJson('/api/production/mark-sold', ['itemIds' => [$item->id]])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $item->refresh();
        $this->assertNull($item->final_status);
        $this->assertNull($item->sold_at);
    }

    public function test_auto_waste_carry_over_wastes_yesterday_unresolved_items(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();

        $yesterday = $this->createProductionItem($outlet, $menu, [
            'belt_status' => 'expired',
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);
        $today = $this->createProductionItem($outlet, $menu);

        $this->artisan('production:close-stale')->assertSuccessful();

        $yesterday->refresh();
        $this->assertSame('waste', $yesterday->final_status);
        $this->assertNotNull($yesterday->wasted_at);
        // Atribusi ke hari produksi (kemarin), bukan hari ini.
        $this->assertTrue($yesterday->wasted_at->isSameDay(now()->subDay()));
        $this->assertDatabaseHas('waste_records', [
            'production_item_id' => $yesterday->id,
        ]);

        // Plate hari ini tidak boleh ikut ter-waste.
        $today->refresh();
        $this->assertNull($today->final_status);
    }

    public function test_get_pos_data_blocked_when_unresolved_items_exist(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $this->createProductionItem($outlet, $menu); // produced today, belum sold/waste

        $this->getJson('/api/reports/pos-data?outletId=' . $outlet->id . '&date=' . now()->toDateString())
            ->assertStatus(422)
            ->assertJsonPath('status', false);
    }

    public function test_remove_expired_route_is_gone(): void
    {
        // POST /production/remove-expired only re-stamped belt_status without
        // any production-day guard, and nothing called it. Removed outright.
        $this->postJson('/api/production/remove-expired', ['itemIds' => []])
            ->assertNotFound();
    }

    /**
     * Bentuk per-piring sudah digantikan bentuk per-batch.
     *
     * Dikunci di sini karena kembalinya tidak akan terlihat sebagai kegagalan:
     * ketiganya berbagi service dan resource dengan yang masih hidup, jadi
     * menghidupkannya lagi cukup satu baris di routes/api.php — dan tablet yang
     * memakainya akan kembali menarik seribu objek tiap 30 detik tanpa ada yang
     * menandai.
     */
    public function test_the_per_plate_belt_routes_are_gone(): void
    {
        $outlet = $this->createOutlet();

        $this->getJson('/api/production/conveyor?outletId=' . $outlet->id)->assertNotFound();
        $this->getJson('/api/production/expired?outletId=' . $outlet->id)->assertNotFound();
        $this->putJson('/api/production/expired/some-id', ['status' => 'sold'])->assertNotFound();
    }

    public function test_close_day_marks_remaining_items_as_sold(): void
    {
        $outlet    = $this->createOutlet();
        $menu      = $this->createMenu();
        $pending   = $this->createProductionItem($outlet, $menu);
        $alreadyWasted = $this->createProductionItem($outlet, $menu, [
            'final_status' => 'waste',
            'wasted_at'    => now(),
        ]);

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 1);

        $pending->refresh();
        $this->assertSame('sold', $pending->final_status);
        $this->assertNotNull($pending->sold_at);

        // Plate yang sudah dibuang tidak boleh berubah jadi terjual.
        $alreadyWasted->refresh();
        $this->assertSame('waste', $alreadyWasted->final_status);
        $this->assertNull($alreadyWasted->sold_at);
    }

    public function test_close_day_leaves_previous_day_items_untouched(): void
    {
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $stale  = $this->createProductionItem($outlet, $menu, [
            'produced_at' => now()->subDay(),
            'expires_at'  => now()->subDay()->addHour(),
        ]);

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 0);

        // Hari kemarin ditutup oleh autoWasteCarryOver(), bukan oleh close-day.
        $stale->refresh();
        $this->assertNull($stale->final_status);
    }

    public function test_close_day_is_scoped_to_one_outlet(): void
    {
        $outlet      = $this->createOutlet();
        $otherOutlet = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $menu        = $this->createMenu();

        $mine    = $this->createProductionItem($outlet, $menu);
        $theirs  = $this->createProductionItem($otherOutlet, $menu);

        $this->postJson('/api/production/close-day', ['outletId' => $outlet->id])
            ->assertOk()
            ->assertJsonPath('data.closed', 1);

        $this->assertSame('sold', $mine->refresh()->final_status);
        $this->assertNull($theirs->refresh()->final_status);
    }

    public function test_close_day_validates_outlet(): void
    {
        $this->postJson('/api/production/close-day', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }
}
