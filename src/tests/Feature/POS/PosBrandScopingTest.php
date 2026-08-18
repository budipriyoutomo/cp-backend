<?php

namespace Tests\Feature\POS;

use App\Models\Brand;
use App\Models\FailedPosMessage;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\POSData;
use App\Services\POSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 3 dari docs/brand-feature-plan.md.
 *
 * Payload POS hanya membawa nama outlet dan nama warna piring. Begitu plate
 * color jadi per-brand, nama warna saja tidak lagi menunjuk satu baris — dua
 * brand boleh sama-sama punya "Merah" dengan harga berbeda. Yang salah kalau
 * pemetaannya meleset bukan label, tapi angka penjualan yang masuk ke
 * rekonsiliasi harian.
 *
 * Trait SeedsProductionData sengaja tidak dipakai di sini: seluruh gunanya
 * justru mengatur brand mana milik siapa secara eksplisit.
 */
class PosBrandScopingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): POSService
    {
        return app(POSService::class);
    }

    private function brand(string $code, string $name): Brand
    {
        return Brand::create(['code' => $code, 'name' => $name, 'is_active' => true]);
    }

    private function outlet(string $code, ?Brand $brand): Outlet
    {
        return Outlet::create([
            'code'      => $code,
            'name'      => $code,
            'brand'     => $brand?->name,
            'brand_id'  => $brand?->id,
            'is_active' => true,
        ]);
    }

    private function plateColor(string $name, ?Brand $brand, int $price): PlateColors
    {
        return PlateColors::create([
            'platename' => $name,
            'brand_id'  => $brand?->id,
            'price'     => $price,
            'is_active' => true,
        ]);
    }

    private function send(string $plateName, string $outletCode, int $sold = 10): array
    {
        return $this->service()->storeFromEvent([
            'platecolor' => $plateName,
            'outlet'     => $outletCode,
            'date'       => '2026-08-14',
            'sold'       => $sold,
        ]);
    }

    public function test_the_same_plate_name_maps_to_each_outlets_own_brand(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $bandung  = $this->outlet('BDG', $maharasa);
        $surabaya = $this->outlet('SBY', $katsuri);

        $merahMaharasa = $this->plateColor('Merah', $maharasa, 15000);
        $merahKatsuri  = $this->plateColor('Merah', $katsuri, 21000);

        $this->send('Merah', 'BDG', 12);
        $this->send('Merah', 'SBY', 7);

        // Inti Fase 3: nama sama, outlet beda, baris yang kena harus beda.
        $this->assertDatabaseHas('posdata', [
            'outlet_id'      => $bandung->id,
            'plate_color_id' => $merahMaharasa->id,
            'sold'           => 12,
        ]);
        $this->assertDatabaseHas('posdata', [
            'outlet_id'      => $surabaya->id,
            'plate_color_id' => $merahKatsuri->id,
            'sold'           => 7,
        ]);
    }

    public function test_a_colour_belonging_only_to_another_brand_is_rejected(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $this->outlet('BDG', $maharasa);
        $this->plateColor('Emas', $katsuri, 60000);

        // Pencarian global yang lama akan mengambil piring Katsuri seharga
        // 60.000 dan menempelkannya ke outlet Maharasa.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('milik brand lain');

        $this->send('Emas', 'BDG');
    }

    public function test_nothing_is_written_when_the_mapping_is_rejected(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $this->outlet('BDG', $maharasa);
        $this->plateColor('Emas', $katsuri, 60000);

        try {
            $this->send('Emas', 'BDG');
        } catch (\Throwable) {
            // ditangkap consumer / replay di produksi
        }

        $this->assertSame(0, POSData::count());
    }

    public function test_a_colour_not_yet_assigned_to_a_brand_is_still_accepted(): void
    {
        // Kelonggaran transisi. Migrasi Fase 2 sengaja meninggalkan
        // plate_colors.brand_id NULL kalau brand-nya lebih dari satu; tanpa
        // jalur ini, basis data seperti itu berhenti menerima data POS sama
        // sekali sampai seseorang membereskan master.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $outlet   = $this->outlet('BDG', $maharasa);
        $merah    = $this->plateColor('Merah', null, 15000);

        $result = $this->send('Merah', 'BDG', 5);

        $this->assertSame('created', $result['status']);
        $this->assertDatabaseHas('posdata', [
            'outlet_id'      => $outlet->id,
            'plate_color_id' => $merah->id,
            'sold'           => 5,
        ]);
    }

    public function test_the_brand_copy_wins_over_an_unassigned_one_with_the_same_name(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $outlet   = $this->outlet('BDG', $maharasa);

        $unassigned = $this->plateColor('Merah', null, 15000);
        $branded    = $this->plateColor('Merah', $maharasa, 18000);

        $this->send('Merah', 'BDG', 4);

        $this->assertDatabaseHas('posdata', [
            'outlet_id'      => $outlet->id,
            'plate_color_id' => $branded->id,
        ]);
        $this->assertDatabaseMissing('posdata', ['plate_color_id' => $unassigned->id]);
    }

    public function test_two_unassigned_colours_with_the_same_name_are_ambiguous(): void
    {
        // Unique index-nya partial dan tidak mengikat baris ber-brand_id NULL,
        // jadi duplikat semacam ini memang bisa ada di masa transisi.
        $maharasa = $this->brand('MHR', 'Maharasa');
        $this->outlet('BDG', $maharasa);

        $this->plateColor('Merah', null, 15000);
        $this->plateColor('Merah', null, 21000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Plate color ambiguous');

        $this->send('Merah', 'BDG');
    }

    public function test_an_outlet_without_a_brand_accepts_the_only_candidate(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $outlet   = $this->outlet('BDG', null);
        $merah    = $this->plateColor('Merah', $maharasa, 15000);

        // Hanya ada satu kandidat, jadi tidak ada yang bisa salah dipilih.
        $result = $this->send('Merah', 'BDG', 3);

        $this->assertSame('created', $result['status']);
        $this->assertDatabaseHas('posdata', [
            'outlet_id'      => $outlet->id,
            'plate_color_id' => $merah->id,
        ]);
    }

    public function test_an_outlet_without_a_brand_refuses_to_choose_between_two_brands(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $this->outlet('BDG', null);
        $this->plateColor('Merah', $maharasa, 15000);
        $this->plateColor('Merah', $katsuri, 21000);

        $this->expectException(\Exception::class);

        $this->send('Merah', 'BDG');
    }

    public function test_a_rejected_message_can_be_replayed_once_the_colour_is_added_to_the_brand(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $outlet = $this->outlet('BDG', $maharasa);
        $this->plateColor('Emas', $katsuri, 60000);

        $message = FailedPosMessage::create([
            'payload' => json_encode(['data' => [
                'platecolor' => 'Emas',
                'outlet'     => 'BDG',
                'date'       => '2026-08-14',
                'sold'       => 9,
            ]]),
            'error' => 'Plate color not found: emas untuk brand outlet BDG',
        ]);

        // Operator membereskan master: warnanya ditambahkan ke brand yang benar.
        $emasMaharasa = $this->plateColor('Emas', $maharasa, 55000);

        $this->artisan('pos:replay-failed')->assertSuccessful();

        $this->assertNotNull($message->fresh()->resolved_at);
        $this->assertDatabaseHas('posdata', [
            'outlet_id'      => $outlet->id,
            'plate_color_id' => $emasMaharasa->id,
            'sold'           => 9,
        ]);
    }

    public function test_upsert_still_targets_the_row_within_the_brand(): void
    {
        $maharasa = $this->brand('MHR', 'Maharasa');
        $katsuri  = $this->brand('KTR', 'Katsuri');

        $bandung = $this->outlet('BDG', $maharasa);
        $this->outlet('SBY', $katsuri);

        $merahMaharasa = $this->plateColor('Merah', $maharasa, 15000);
        $this->plateColor('Merah', $katsuri, 21000);

        $first  = $this->send('Merah', 'BDG', 5);
        $second = $this->send('Merah', 'BDG', 11);

        $this->assertSame('created', $first['status']);
        $this->assertSame('updated', $second['status']);
        $this->assertSame(1, POSData::count());
        $this->assertDatabaseHas('posdata', [
            'plate_color_id' => $merahMaharasa->id,
            'sold'           => 11,
        ]);
    }
}
