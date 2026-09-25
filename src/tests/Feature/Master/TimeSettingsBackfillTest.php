<?php

namespace Tests\Feature\Master;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Backfill setelan waktu per brand.
 *
 * Diuji seperti kode biasa, dengan alasan yang sama seperti
 * BrandBackfillMigrationTest: backfill yang diam-diam tidak jalan tidak
 * kelihatan sampai hari rilis, dan yang hilang adalah dua layar yang dipakai
 * dapur tiap hari — planning dan penanda di conveyor.
 *
 * RefreshDatabase sudah menjalankan seluruh migration terhadap basis data
 * kosong, jadi saat itu tidak ada satu brand pun untuk diisi. Di sini berkas
 * migration-nya dimuat ulang dan `up()` dipanggil lagi terhadap data yang
 * memang sudah disiapkan. Aman karena langkahnya idempoten.
 */
class TimeSettingsBackfillTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_24_000300_backfill_brand_time_settings.php');
        $migration->up();
    }

    public function test_it_seeds_five_markers_and_twenty_two_slots_per_brand(): void
    {
        $brand = $this->defaultBrand();

        $this->runBackfill();

        $markers = DB::table('time_markers')->where('brand_id', $brand->id)->orderBy('sort_order')->get();
        $slots   = DB::table('time_slots')->where('brand_id', $brand->id)->orderBy('sort_order')->get();

        $this->assertCount(5, $markers);
        $this->assertSame(
            ['Biru', 'Hitam', 'Merah', 'Kuning', 'Hijau'],
            $markers->pluck('label')->all()
        );

        // 10:00 sampai 21:00, kelipatan 30 menit.
        $this->assertCount(22, $slots);
        $this->assertSame('10:00:00', substr((string) $slots->first()->start_time, 0, 8));
        $this->assertSame('21:00:00', substr((string) $slots->last()->end_time, 0, 8));
    }

    public function test_markers_are_attached_to_slots_in_a_repeating_cycle(): void
    {
        $brand = $this->defaultBrand();

        $this->runBackfill();

        $markerIds = DB::table('time_markers')
            ->where('brand_id', $brand->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        $slots = DB::table('time_slots')
            ->where('brand_id', $brand->id)
            ->orderBy('sort_order')
            ->get();

        foreach ($slots as $index => $slot) {
            $this->assertSame(
                $markerIds[$index % 5],
                $slot->time_marker_id,
                "Slot ke-{$index} tidak memakai penanda yang diharapkan."
            );
        }
    }

    /**
     * Migration ini akan ikut jalan lagi di setiap `migrate` berikutnya pada
     * basis data yang belum mencatatnya, dan setelan yang sudah disesuaikan
     * admin tidak boleh tertimpa.
     */
    public function test_running_it_twice_changes_nothing(): void
    {
        $brand = $this->defaultBrand();

        $this->runBackfill();

        DB::table('time_slots')
            ->where('brand_id', $brand->id)
            ->where('sort_order', 0)
            ->update(['end_time' => '10:20:00']);

        $this->runBackfill();

        $this->assertSame(5, DB::table('time_markers')->where('brand_id', $brand->id)->count());
        $this->assertSame(22, DB::table('time_slots')->where('brand_id', $brand->id)->count());

        $firstSlot = DB::table('time_slots')
            ->where('brand_id', $brand->id)
            ->where('sort_order', 0)
            ->first();

        $this->assertSame('10:20:00', substr((string) $firstSlot->end_time, 0, 8));
    }

    public function test_each_brand_gets_its_own_settings(): void
    {
        $first  = $this->defaultBrand();
        $second = $this->createBrand(['code' => 'BR2', 'name' => 'Brand Dua']);

        $this->runBackfill();

        foreach ([$first, $second] as $brand) {
            $this->assertSame(5, DB::table('time_markers')->where('brand_id', $brand->id)->count());
            $this->assertSame(22, DB::table('time_slots')->where('brand_id', $brand->id)->count());
        }
    }

    public function test_it_fills_plate_color_hex_for_names_it_knows(): void
    {
        $brand = $this->defaultBrand();

        $merah   = $this->createPlateColor(['platename' => 'Merah', 'brand_id' => $brand->id]);
        $unknown = $this->createPlateColor(['platename' => 'Motif Sakura', 'brand_id' => $brand->id]);

        $this->runBackfill();

        $this->assertSame(
            '#EF4444',
            DB::table('plate_colors')->where('id', $merah->id)->value('color_hex')
        );

        // Nama di luar daftar dibiarkan kosong — badge jatuh ke warna cadangan.
        // Kosong dan jujur lebih baik daripada terisi tebakan.
        $this->assertNull(
            DB::table('plate_colors')->where('id', $unknown->id)->value('color_hex')
        );
    }

    public function test_an_existing_hex_is_never_overwritten(): void
    {
        $brand = $this->defaultBrand();
        $color = $this->createPlateColor([
            'platename' => 'Merah',
            'brand_id'  => $brand->id,
            'color_hex' => '#123456',
        ]);

        $this->runBackfill();

        $this->assertSame(
            '#123456',
            DB::table('plate_colors')->where('id', $color->id)->value('color_hex')
        );
    }

    /**
     * Inti masalah yang melahirkan fitur ini: 5 penanda x 30 menit = 150 menit,
     * sementara sebagian besar menu hidup 180 menit. Migration tidak menambah
     * penanda sendiri — berapa warna yang tersedia itu urusan fisik di dapur —
     * jadi yang harus terjadi adalah peringatan, bukan tebakan.
     */
    public function test_it_warns_when_the_marker_cycle_is_shorter_than_the_longest_shelf_life(): void
    {
        $brand = $this->defaultBrand();
        $color = $this->createPlateColor(['brand_id' => $brand->id]);

        $this->createMenu($color, ['shelf_life' => 180, 'brand_id' => $brand->id]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, '150 menit')
                && str_contains($message, '180 menit'));

        $this->runBackfill();
    }

    public function test_it_stays_quiet_when_the_cycle_covers_the_shelf_life(): void
    {
        $brand = $this->defaultBrand();
        $color = $this->createPlateColor(['brand_id' => $brand->id]);

        $this->createMenu($color, ['shelf_life' => 120, 'brand_id' => $brand->id]);

        Log::shouldReceive('warning')->never();

        $this->runBackfill();
    }
}
