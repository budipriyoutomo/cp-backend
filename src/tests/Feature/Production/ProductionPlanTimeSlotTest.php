<?php

namespace Tests\Feature\Production;

use App\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Hubungan antara plan produksi dan master time slot.
 *
 * `production_plans.time_slot` sengaja tetap teks, bukan foreign key: mengubah
 * jam sebuah slot tidak boleh mengubah plan yang sudah tersimpan, sama seperti
 * `wasted_at` pada carry-over — laporan hari lalu tidak berubah karena setelan
 * hari ini. Konsekuensinya label harus divalidasi saat disimpan, kalau tidak
 * salah ketik satu karakter membuat slot baru yang tidak pernah muncul di layar.
 */
class ProductionPlanTimeSlotTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    private function slot(string $brandId, string $start, string $end): TimeSlot
    {
        return TimeSlot::create([
            'brand_id'   => $brandId,
            'start_time' => $start,
            'end_time'   => $end,
            'is_active'  => true,
        ]);
    }

    public function test_a_plan_can_use_a_slot_the_brand_owns(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->slot($outlet->brand_id, '10:00:00', '10:30:00');

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '10:00-10:30', 'items' => [['plateColorId' => $color->id, 'qty' => 5]]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('production_plans', ['time_slot' => '10:00-10:30']);
    }

    public function test_a_plan_cannot_invent_a_slot(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->slot($outlet->brand_id, '10:00:00', '10:30:00');

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '08:00-09:00', 'items' => [['plateColorId' => $color->id, 'qty' => 5]]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertDatabaseCount('production_plans', 0);
    }

    public function test_a_slot_belonging_to_another_brand_is_not_accepted(): void
    {
        $outlet = $this->createOutlet();
        $other  = $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->slot($outlet->brand_id, '10:00:00', '10:30:00');
        $this->slot($other->id, '14:00:00', '14:30:00');

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '14:00-14:30', 'items' => [['plateColorId' => $color->id, 'qty' => 5]]],
            ],
        ])->assertStatus(422);
    }

    /**
     * Kelonggaran transisi, sejalan dengan `brand_id` NULL di master lain:
     * instalasi yang belum menjalankan backfill tidak boleh kehilangan
     * kemampuan menyimpan plan.
     */
    public function test_a_brand_without_any_slot_yet_can_still_save_a_plan(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->assertDatabaseCount('time_slots', 0);

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '08:00-09:00', 'items' => [['plateColorId' => $color->id, 'qty' => 5]]],
            ],
        ])->assertOk();
    }

    /** Slot yang dinonaktifkan berarti "jangan dipakai lagi mulai sekarang". */
    public function test_an_inactive_slot_cannot_be_used_by_a_new_plan(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->slot($outlet->brand_id, '10:00:00', '10:30:00');
        $this->slot($outlet->brand_id, '11:00:00', '11:30:00')->update(['is_active' => false]);

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '11:00-11:30', 'items' => [['plateColorId' => $color->id, 'qty' => 5]]],
            ],
        ])->assertStatus(422);
    }

    /**
     * `getPlanFormatted()` dulu mengambil baris tanpa `orderBy` sama sekali,
     * jadi urutannya diserahkan ke PostgreSQL. Layar planning menampilkannya apa
     * adanya — dan sampai Fase 6, warna penandanya dipilih dari NOMOR URUT
     * BARIS, jadi urutan yang teracak berarti penanda yang salah.
     *
     * Catatan jujur: suite ini jalan di SQLite, yang cenderung mengembalikan
     * baris sesuai urutan sisip, jadi test ini belum tentu merah kalau
     * `orderBy` dicabut. Yang dikunci di sini adalah niatnya.
     */
    public function test_a_saved_plan_comes_back_in_time_order(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        foreach ([['14:00:00', '14:30:00'], ['09:00:00', '09:30:00'], ['11:00:00', '11:30:00']] as [$start, $end]) {
            $this->slot($outlet->brand_id, $start, $end);
        }

        // Sengaja dikirim dengan urutan acak.
        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '14:00-14:30', 'items' => [['plateColorId' => $color->id, 'qty' => 1]]],
                ['timeSlot' => '09:00-09:30', 'items' => [['plateColorId' => $color->id, 'qty' => 2]]],
                ['timeSlot' => '11:00-11:30', 'items' => [['plateColorId' => $color->id, 'qty' => 3]]],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/production/plan?outletId=' . $outlet->id . '&date=2026-06-17')
            ->assertOk();

        $this->assertSame(
            ['09:00-09:30', '11:00-11:30', '14:00-14:30'],
            array_column($response->json('data'), 'timeSlot')
        );
    }

    public function test_reading_a_plan_rejects_a_malformed_outlet_id(): void
    {
        $this->getJson('/api/production/plan?outletId=abc&date=2026-06-17')->assertStatus(422);
    }

    /**
     * Bentuk respons plan sengaja TIDAK diubah oleh fitur ini.
     *
     * Frontend lama memperlakukan setiap kunci selain `timeSlot` sebagai nama
     * warna piring dan melemparnya ke peta plate color. Menambahkan penanda ke
     * baris plan akan mematikan Save Plan di bundel lama begitu backend naik —
     * dan tablet dapur menyimpan bundel lama di service worker. Penanda diambil
     * layar dari `/master/time-slot`, yang memang sudah dipanggil conveyor.
     */
    public function test_plan_rows_carry_nothing_but_the_slot_and_its_colors(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor(['platename' => 'Hijau']);

        $this->slot($outlet->brand_id, '10:00:00', '10:30:00');

        $this->postJson('/api/production/plan', [
            'outletId' => $outlet->id,
            'date'     => '2026-06-17',
            'plan'     => [
                ['timeSlot' => '10:00-10:30', 'items' => [['plateColorId' => $color->id, 'qty' => 5]]],
            ],
        ])->assertOk();

        $row = $this->getJson('/api/production/plan?outletId=' . $outlet->id . '&date=2026-06-17')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(['timeSlot', 'hijau'], array_keys($row));
    }
}
