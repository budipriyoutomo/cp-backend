<?php

namespace Tests\Feature\Master;

use App\Models\TimeMarker;
use App\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Setelan waktu per brand: time slot dan penanda.
 *
 * Yang dijaga di sini adalah pagar-pagarnya, bukan CRUD-nya. Setelan ini
 * menentukan apa yang dilihat SELURUH dapur — slot yang tumpang tindih membuat
 * satu jam produksi cocok dengan dua slot sekaligus, dan penanda milik brand
 * lain menampilkan warna yang tidak pernah dipakai outlet itu.
 */
class TimeSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    private function markerPayload(string $brandId, array $overrides = []): array
    {
        return array_merge([
            'brand_id'   => $brandId,
            'label'      => 'Biru',
            'color_hex'  => '#3B82F6',
            'sort_order' => 0,
        ], $overrides);
    }

    private function slotPayload(string $brandId, array $overrides = []): array
    {
        return array_merge([
            'brand_id'   => $brandId,
            'start_time' => '10:00',
            'end_time'   => '10:30',
            'sort_order' => 0,
        ], $overrides);
    }

    // ==========================
    // PENANDA
    // ==========================

    public function test_admin_can_create_a_marker(): void
    {
        $brand = $this->defaultBrand();

        $this->postJson('/api/master/time-marker', $this->markerPayload($brand->id))
            ->assertCreated()
            ->assertJsonPath('data.label', 'Biru')
            ->assertJsonPath('data.color_hex', '#3B82F6');

        $this->assertDatabaseHas('time_markers', ['brand_id' => $brand->id, 'label' => 'Biru']);
    }

    public function test_marker_color_must_be_a_six_digit_hex(): void
    {
        $brand = $this->defaultBrand();

        foreach (['biru', '#FFF', '3B82F6', '#GGGGGG'] as $bad) {
            $this->postJson('/api/master/time-marker', $this->markerPayload($brand->id, [
                'label'     => 'Warna ' . $bad,
                'color_hex' => $bad,
            ]))->assertStatus(422)->assertJsonValidationErrors(['color_hex']);
        }
    }

    public function test_marker_label_is_unique_within_a_brand_but_free_across_brands(): void
    {
        $first  = $this->defaultBrand();
        $second = $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);

        $this->postJson('/api/master/time-marker', $this->markerPayload($first->id))->assertCreated();

        $this->postJson('/api/master/time-marker', $this->markerPayload($first->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);

        // Dua brand boleh sama-sama punya "Biru" — inti dari setelan per brand.
        $this->postJson('/api/master/time-marker', $this->markerPayload($second->id))->assertCreated();
    }

    // ==========================
    // SLOT
    // ==========================

    public function test_admin_can_create_a_slot_with_a_marker(): void
    {
        $brand  = $this->defaultBrand();
        $marker = TimeMarker::create($this->markerPayload($brand->id));

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'time_marker_id' => $marker->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.label', '10:00-10:30');

        // Jam dinormalkan ke HH:MM:SS sebelum disimpan — unique index dan label
        // kanonis sama-sama bergantung pada satu ejaan.
        $this->assertDatabaseHas('time_slots', [
            'brand_id'   => $brand->id,
            'start_time' => '10:00:00',
            'end_time'   => '10:30:00',
        ]);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $brand = $this->defaultBrand();

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => '11:00',
            'end_time'   => '10:00',
        ]))->assertStatus(422)->assertJsonValidationErrors(['end_time']);

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => '11:00',
            'end_time'   => '11:00',
        ]))->assertStatus(422)->assertJsonValidationErrors(['end_time']);
    }

    /**
     * Unique index hanya menangkap jam mulai yang sama persis. Tumpang tindih
     * yang lebih halus lolos darinya — dan akibatnya satu jam produksi jatuh ke
     * dua slot sekaligus, jadi penanda sebuah piring bergantung pada baris mana
     * yang kebetulan ditemukan duluan.
     *
     * @dataProvider overlappingSlots
     */
    public function test_overlapping_slots_are_rejected(string $start, string $end): void
    {
        $brand = $this->defaultBrand();

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => '10:00',
            'end_time'   => '11:00',
        ]))->assertCreated();

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => $start,
            'end_time'   => $end,
        ]))->assertStatus(422)->assertJsonValidationErrors(['start_time']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function overlappingSlots(): array
    {
        return [
            'jam mulai sama'   => ['10:00', '10:30'],
            'mulai di tengah'  => ['10:30', '11:30'],
            'selesai di tengah'=> ['09:30', '10:30'],
            'menelan seluruh'  => ['09:00', '12:00'],
            'di dalam'         => ['10:15', '10:45'],
        ];
    }

    public function test_slots_that_only_touch_are_allowed(): void
    {
        $brand = $this->defaultBrand();

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => '10:00',
            'end_time'   => '10:30',
        ]))->assertCreated();

        // 10:30 selesai, 10:30 mulai — bersinggungan, bukan tumpang tindih.
        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => '10:30',
            'end_time'   => '11:00',
        ]))->assertCreated();
    }

    public function test_a_slot_cannot_borrow_a_marker_from_another_brand(): void
    {
        $brand  = $this->defaultBrand();
        $other  = $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);
        $marker = TimeMarker::create($this->markerPayload($other->id));

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'time_marker_id' => $marker->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['time_marker_id']);
    }

    public function test_updating_a_slot_does_not_collide_with_itself(): void
    {
        $brand = $this->defaultBrand();

        $id = $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/master/time-slot/{$id}", $this->slotPayload($brand->id, [
            'end_time' => '10:45',
        ]))->assertOk()->assertJsonPath('data.label', '10:00-10:45');
    }

    // ==========================
    // PENYARINGAN PER OUTLET
    // ==========================

    public function test_reading_by_outlet_returns_only_that_brands_slots(): void
    {
        $brand  = $this->defaultBrand();
        $other  = $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);
        $outlet = $this->createOutlet();

        TimeSlot::create($this->slotPayload($brand->id));
        TimeSlot::create($this->slotPayload($other->id, ['start_time' => '12:00', 'end_time' => '12:30']));

        $response = $this->getJson('/api/master/time-slot?outlet_id=' . $outlet->id . '&per_page=all')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('10:00-10:30', $response->json('data.0.label'));
    }

    /**
     * Outlet tanpa brand menerima daftar KOSONG, bukan slot semua brand.
     *
     * Ini sengaja berbeda dari `ResolvesOutletBrand::scopeToBrand()`, yang
     * melewatkan penyaringan saat brand NULL demi master yang belum di-backfill.
     * Di sini kelonggaran itu merusak: layar planning akan menampilkan jam
     * kembar berkali-kali dan satu jam produksi cocok dengan banyak slot.
     */
    public function test_an_outlet_without_a_brand_gets_an_empty_list(): void
    {
        $brand  = $this->defaultBrand();
        $outlet = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta', 'brand_id' => null, 'brand' => null]);

        TimeSlot::create($this->slotPayload($brand->id));

        $response = $this->getJson('/api/master/time-slot?outlet_id=' . $outlet->id . '&per_page=all')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    // ==========================
    // GERBANG AKSES
    // ==========================

    public function test_kitchen_staff_can_read_but_cannot_write(): void
    {
        $brand = $this->defaultBrand();
        TimeSlot::create($this->slotPayload($brand->id));

        // Layar conveyor dan expired membutuhkan ini; membacanya tidak sensitif.
        $this->actingAsRole('kitchen');

        $this->getJson('/api/master/time-slot')->assertOk();
        $this->getJson('/api/master/time-marker')->assertOk();

        $this->postJson('/api/master/time-slot', $this->slotPayload($brand->id, [
            'start_time' => '14:00',
            'end_time'   => '14:30',
        ]))->assertStatus(403);
    }

    public function test_guests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/master/time-slot')->assertStatus(401);
        $this->postJson('/api/master/time-marker', [])->assertStatus(401);
    }

    /**
     * Route::crud mendaftarkan GET /{id} untuk semua master, dan empat master
     * lama menjawab 500 di sana karena method-nya tidak pernah ditulis.
     */
    public function test_show_answers_404_for_an_unknown_id(): void
    {
        $this->getJson('/api/master/time-slot/00000000-0000-4000-8000-000000000000')
            ->assertStatus(404)
            ->assertJsonPath('status', false);

        $this->getJson('/api/master/time-marker/00000000-0000-4000-8000-000000000000')
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_a_malformed_id_segment_answers_404_not_500(): void
    {
        $this->getJson('/api/master/time-slot/abc')->assertStatus(404);
        $this->getJson('/api/master/time-marker/abc')->assertStatus(404);
    }

    // ==========================
    // RINGKASAN SIKLUS PENANDA
    // ==========================

    /**
     * Masalah yang melahirkan fitur ini, dalam bentuk test: penanda yang sama
     * berulang tiap 150 menit sementara menu bertahan 180 menit, jadi antara
     * menit ke-150 dan ke-180 ada dua batch berwarna sama di belt.
     */
    public function test_summary_warns_when_a_marker_repeats_sooner_than_the_shelf_life(): void
    {
        $brand  = $this->defaultBrand();
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['brand_id' => $brand->id]);

        $this->createMenu($color, ['shelf_life' => 180, 'brand_id' => $brand->id]);

        $marker = TimeMarker::create($this->markerPayload($brand->id));

        TimeSlot::create($this->slotPayload($brand->id, ['time_marker_id' => $marker->id]));
        TimeSlot::create($this->slotPayload($brand->id, [
            'start_time'     => '12:30',
            'end_time'       => '13:00',
            'time_marker_id' => $marker->id,
        ]));

        $this->getJson('/api/master/time-settings/summary?outlet_id=' . $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.repeatMinutes', 150)
            ->assertJsonPath('data.longestShelfLife', 180);
    }

    public function test_summary_is_quiet_when_the_gap_covers_the_shelf_life(): void
    {
        $brand  = $this->defaultBrand();
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['brand_id' => $brand->id]);

        $this->createMenu($color, ['shelf_life' => 120, 'brand_id' => $brand->id]);

        $marker = TimeMarker::create($this->markerPayload($brand->id));

        TimeSlot::create($this->slotPayload($brand->id, ['time_marker_id' => $marker->id]));
        TimeSlot::create($this->slotPayload($brand->id, [
            'start_time'     => '12:30',
            'end_time'       => '13:00',
            'time_marker_id' => $marker->id,
        ]));

        $this->getJson('/api/master/time-settings/summary?outlet_id=' . $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.message', null);
    }

    /** Penanda yang tidak pernah dipakai dua kali tidak punya batas untuk dilanggar. */
    public function test_summary_is_quiet_when_no_marker_repeats(): void
    {
        $brand  = $this->defaultBrand();
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['brand_id' => $brand->id]);

        $this->createMenu($color, ['shelf_life' => 180, 'brand_id' => $brand->id]);

        $first  = TimeMarker::create($this->markerPayload($brand->id));
        $second = TimeMarker::create($this->markerPayload($brand->id, ['label' => 'Hitam', 'sort_order' => 1]));

        TimeSlot::create($this->slotPayload($brand->id, ['time_marker_id' => $first->id]));
        TimeSlot::create($this->slotPayload($brand->id, [
            'start_time'     => '10:30',
            'end_time'       => '11:00',
            'time_marker_id' => $second->id,
        ]));

        $this->getJson('/api/master/time-settings/summary?outlet_id=' . $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.repeatMinutes', null);
    }

    public function test_summary_rejects_a_malformed_outlet_id(): void
    {
        $this->getJson('/api/master/time-settings/summary?outlet_id=abc')->assertStatus(422);
    }

    /**
     * Halaman setelan admin bekerja per brand dan tidak punya outlet sama
     * sekali, jadi endpoint yang sama harus bisa ditanya dari sudut itu.
     */
    public function test_summary_can_be_asked_by_brand_instead_of_outlet(): void
    {
        $brand  = $this->defaultBrand();
        $color  = $this->createPlateColor(['brand_id' => $brand->id]);

        $this->createMenu($color, ['shelf_life' => 180, 'brand_id' => $brand->id]);

        $marker = TimeMarker::create($this->markerPayload($brand->id));

        TimeSlot::create($this->slotPayload($brand->id, ['time_marker_id' => $marker->id]));
        TimeSlot::create($this->slotPayload($brand->id, [
            'start_time'     => '12:30',
            'end_time'       => '13:00',
            'time_marker_id' => $marker->id,
        ]));

        $this->getJson('/api/master/time-settings/summary?brand_id=' . $brand->id)
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.repeatMinutes', 150);
    }

    public function test_summary_needs_either_an_outlet_or_a_brand(): void
    {
        $this->getJson('/api/master/time-settings/summary')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outlet_id', 'brand_id']);
    }
}
