<?php

namespace Tests\Feature\Master;

use App\Models\TimeMarker;
use App\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Kontrak antara backend dan layar setelan.
 *
 * Test komponen di frontend memalsukan hook-nya, jadi ia membuktikan layar
 * memanggil yang benar — bukan bahwa server menerimanya. Yang diuji di sini
 * justru sambungannya: nama field yang dibaca `lib/api/services/time-slots.ts`,
 * dan bentuk payload yang benar-benar dikirim `brand-settings-admin.tsx`.
 */
class TimeSettingsContractTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    private function seedSlot(): array
    {
        $brand = $this->defaultBrand();

        $marker = TimeMarker::create([
            'brand_id'   => $brand->id,
            'label'      => 'Biru',
            'color_hex'  => '#3B82F6',
            'sort_order' => 0,
        ]);

        $slot = TimeSlot::create([
            'brand_id'       => $brand->id,
            'start_time'     => '10:00:00',
            'end_time'       => '10:30:00',
            'time_marker_id' => $marker->id,
            'sort_order'     => 0,
        ]);

        return [$brand, $marker, $slot];
    }

    /**
     * Nama field persis yang dibaca transformTimeSlot(). Satu saja meleset dan
     * layar menampilkan slot tanpa jam, tanpa pernah melempar error.
     */
    public function test_slot_payload_carries_every_field_the_client_reads(): void
    {
        [$brand] = $this->seedSlot();

        $row = $this->getJson('/api/master/time-slot?brand_id=' . $brand->id . '&per_page=all')
            ->assertOk()
            ->json('data.0');

        foreach (['id', 'brand_id', 'start_time', 'end_time', 'label', 'sort_order', 'is_active', 'marker'] as $field) {
            $this->assertArrayHasKey($field, $row, "Field {$field} hilang dari payload slot.");
        }

        $this->assertSame('10:00-10:30', $row['label']);
        $this->assertSame('10:00:00', substr((string) $row['start_time'], 0, 8));

        foreach (['id', 'brand_id', 'label', 'color_hex', 'sort_order', 'is_active'] as $field) {
            $this->assertArrayHasKey($field, $row['marker'], "Field {$field} hilang dari penanda.");
        }

        $this->assertSame('#3B82F6', $row['marker']['color_hex']);
    }

    public function test_marker_payload_carries_every_field_the_client_reads(): void
    {
        [$brand] = $this->seedSlot();

        $row = $this->getJson('/api/master/time-marker?brand_id=' . $brand->id . '&per_page=all')
            ->assertOk()
            ->json('data.0');

        foreach (['id', 'brand_id', 'label', 'color_hex', 'sort_order', 'is_active'] as $field) {
            $this->assertArrayHasKey($field, $row, "Field {$field} hilang dari payload penanda.");
        }
    }

    /** Halaman setelan admin menyaring per brand, bukan per outlet. */
    public function test_filtering_by_brand_id_excludes_other_brands(): void
    {
        [$brand] = $this->seedSlot();
        $other = $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);

        TimeSlot::create([
            'brand_id'   => $other->id,
            'start_time' => '14:00:00',
            'end_time'   => '14:30:00',
        ]);

        $rows = $this->getJson('/api/master/time-slot?brand_id=' . $brand->id . '&per_page=all')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('10:00-10:30', $rows[0]['label']);
    }

    // ==========================
    // PAYLOAD YANG BENAR-BENAR DIKIRIM LAYAR
    // ==========================

    /**
     * Layar setelan menyimpan per baris — ganti penanda, matikan slot, ubah jam
     * — tapi setiap aksi mengirim BARIS UTUH, bukan field yang berubah saja.
     *
     * Itu bukan pemborosan: pemeriksaan tumpang tindih jam tidak bisa dilakukan
     * tanpa mengetahui jam barunya, jadi `rulesForUpdate()` mewajibkan
     * `brand_id`, `start_time`, dan `end_time`. Lihat `slotPayload()` di
     * brand-settings-admin.tsx.
     *
     * @dataProvider rowLevelSlotEdits
     */
    public function test_a_row_level_slot_edit_is_accepted(array $patch): void
    {
        [$brand, , $slot] = $this->seedSlot();

        $payload = array_merge([
            'brand_id'       => $brand->id,
            'start_time'     => '10:00',
            'end_time'       => '10:30',
            'time_marker_id' => $slot->time_marker_id,
            'sort_order'     => 0,
            'is_active'      => true,
        ], $patch);

        $this->putJson("/api/master/time-slot/{$slot->id}", $payload)->assertOk();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function rowLevelSlotEdits(): array
    {
        return [
            'lepas penanda' => [['time_marker_id' => null]],
            'matikan slot'  => [['is_active' => false]],
            'ubah jam'      => [['end_time' => '10:45']],
        ];
    }

    /**
     * @dataProvider rowLevelMarkerEdits
     */
    public function test_a_row_level_marker_edit_is_accepted(array $patch): void
    {
        [$brand, $marker] = $this->seedSlot();

        $payload = array_merge([
            'brand_id'   => $brand->id,
            'label'      => 'Biru',
            'color_hex'  => '#3B82F6',
            'sort_order' => 0,
            'is_active'  => true,
        ], $patch);

        $this->putJson("/api/master/time-marker/{$marker->id}", $payload)->assertOk();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function rowLevelMarkerEdits(): array
    {
        return [
            'ganti warna'     => [['color_hex' => '#123456']],
            'matikan penanda' => [['is_active' => false]],
        ];
    }

    /**
     * Sisi lain dari kontrak itu, dikunci supaya tidak hilang diam-diam.
     *
     * Jebakannya halus: `fillSoleBrand()` mengisi `brand_id` sendiri selama
     * basis data cuma punya SATU brand, jadi payload kurang lengkap tetap lolos
     * di instalasi satu brand dan baru gagal setelah brand kedua dibuat. Test
     * ini sengaja membuat brand kedua supaya kelonggaran itu tidak menutupi
     * apa pun.
     */
    public function test_a_partial_update_is_refused(): void
    {
        [, , $slot] = $this->seedSlot();
        $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);

        $this->putJson("/api/master/time-slot/{$slot->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand_id', 'start_time', 'end_time']);
    }

    /**
     * Lepas penanda harus benar-benar menulis NULL, bukan diam-diam diabaikan.
     */
    public function test_clearing_a_marker_actually_clears_it(): void
    {
        [$brand, , $slot] = $this->seedSlot();

        $this->putJson("/api/master/time-slot/{$slot->id}", [
            'brand_id'       => $brand->id,
            'start_time'     => '10:00',
            'end_time'       => '10:30',
            'time_marker_id' => null,
            'is_active'      => true,
        ])->assertOk();

        $this->assertNull($slot->fresh()->time_marker_id);
    }
}
