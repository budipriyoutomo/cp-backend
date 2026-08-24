<?php

namespace Tests\Feature\Production;

use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Import produksi backdate.
 *
 * Modul ini ada karena hari yang sudah lewat tidak punya jalur masuk lain:
 * markSold/markWaste menolak plate dari hari sebelumnya, dan close-stale sudah
 * menutup sisanya jadi waste. Yang dijaga test ini terutama dua hal yang kalau
 * salah tidak memunculkan error apa pun — atribusi waktu ke hari produksi, dan
 * resolusi kode menu di dalam brand outlet.
 */
class ProductionBackdateImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const PREVIEW_URL = '/api/production/import-backdate/preview';
    private const IMPORT_URL  = '/api/production/import-backdate';

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('backdate.csv', $content);
    }

    private function yesterday(): string
    {
        return now()->subDay()->toDateString();
    }

    /*
    |--------------------------------------------------------------------------
    | PREVIEW
    |--------------------------------------------------------------------------
    */

    public function test_preview_summarises_a_valid_file_without_writing_anything(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001', 'menuname' => 'Salmon Nigiri']);

        $date = $this->yesterday();

        $response = $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "date,menu_code,quantity,final_status\n"
                . "{$date},SUS-001,12,sold\n"
                . "{$date},SUS-001,3,waste\n"
            ),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.totalRows', 2)
            ->assertJsonPath('data.summary.validRows', 2)
            ->assertJsonPath('data.summary.errorRows', 0)
            ->assertJsonPath('data.summary.totalPlates', 15)
            ->assertJsonPath('data.summary.soldPlates', 12)
            ->assertJsonPath('data.summary.wastePlates', 3)
            ->assertJsonPath('data.summary.dates', [$date])
            ->assertJsonPath('data.rows.0.menuName', 'Salmon Nigiri')
            ->assertJsonPath('data.rows.0.plateColorName', 'Merah');

        $this->assertDatabaseCount('production_items', 0);
    }

    public function test_preview_reports_every_bad_row_at_once_instead_of_failing_on_the_first(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $date  = $this->yesterday();
        $today = now()->toDateString();

        $response = $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "date,menu_code,quantity,final_status\n"
                . "{$date},NOPE-9,5,sold\n"          // kode menu tidak ada
                . "{$today},SUS-001,5,sold\n"        // hari ini bukan backdate
                . "{$date},SUS-001,5,dijual\n"       // status tidak dikenal
                . "{$date},SUS-001,nol,sold\n"       // quantity bukan angka
                . "{$date},SUS-001,4,sold\n"         // satu-satunya yang benar
            ),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.totalRows', 5)
            ->assertJsonPath('data.summary.validRows', 1)
            ->assertJsonPath('data.summary.errorRows', 4)
            ->assertJsonPath('data.summary.totalPlates', 4);

        $rows = $response->json('data.rows');

        $this->assertStringContainsString('NOPE-9', $rows[0]['errors'][0]);
        $this->assertStringContainsString('sebelum hari ini', $rows[1]['errors'][0]);
        $this->assertStringContainsString('sold atau waste', $rows[2]['errors'][0]);
        $this->assertStringContainsString('bilangan bulat', $rows[3]['errors'][0]);
        $this->assertSame([], $rows[4]['errors']);
    }

    public function test_a_missing_required_column_is_rejected_before_any_row_is_read(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();

        $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv("date,menu_code,quantity\n{$this->yesterday()},SUS-001,4\n"),
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJson(fn ($json) => $json->where(
                'message',
                fn ($message) => str_contains($message, 'final_status')
            )->etc());
    }

    /**
     * Excel berbahasa Indonesia menulis `;` dan menempelkan BOM. Tanpa deteksi,
     * seluruh baris terbaca sebagai satu kolom dan header pertama tidak cocok.
     */
    public function test_a_semicolon_file_with_a_bom_and_indonesian_headers_is_understood(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $date = now()->subDay()->format('d/m/Y');

        $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "\xEF\xBB\xBFtanggal;kode_menu;jumlah;status;catatan\n"
                . "{$date};SUS-001;7;terjual;susulan\n"
            ),
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.validRows', 1)
            ->assertJsonPath('data.summary.totalPlates', 7)
            ->assertJsonPath('data.rows.0.finalStatus', 'sold')
            ->assertJsonPath('data.rows.0.notes', 'susulan');
    }

    /*
    |--------------------------------------------------------------------------
    | IMPORT
    |--------------------------------------------------------------------------
    */

    public function test_import_creates_one_row_per_plate_attributed_to_the_production_day(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu(null, ['code' => 'SUS-001', 'shelf_life' => 45]);

        $date = $this->yesterday();

        $this->post(self::IMPORT_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "date,menu_code,quantity,final_status,time\n"
                . "{$date},SUS-001,2,sold,09:30\n"
                . "{$date},SUS-001,1,waste,\n"
            ),
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 3)
            ->assertJsonPath('data.wasteRecords', 1);

        $this->assertDatabaseCount('production_items', 3);

        // Jam dari berkas dipakai apa adanya, dan expires_at ikut shelf_life menu.
        $this->assertDatabaseHas('production_items', [
            'menu_id'      => $menu->id,
            'outlet_id'    => $outlet->id,
            'plate_color'  => $menu->plate_color_id,
            'quantity'     => 1,
            'final_status' => 'sold',
            'belt_status'  => 'expired',
            'produced_at'  => "{$date} 09:30:00",
            'sold_at'      => "{$date} 09:30:00",
            'expires_at'   => "{$date} 10:15:00",
        ]);

        // Tanpa kolom `time`, tengah hari — bukan 00:00, yang akan terbaca
        // sebagai sisa hari sebelumnya di layar mana pun yang menampilkan jam.
        $this->assertDatabaseHas('production_items', [
            'final_status' => 'waste',
            'produced_at'  => "{$date} 12:00:00",
            'wasted_at'    => "{$date} 12:00:00",
        ]);

        // Analisis waste membaca waste_records, bukan production_items.
        $this->assertDatabaseHas('waste_records', [
            'menu_id'     => $menu->id,
            'outlet_id'   => $outlet->id,
            'quantity'    => 1,
            'recorded_at' => "{$date} 12:00:00",
        ]);
    }

    public function test_no_row_is_written_when_any_row_is_invalid(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $date = $this->yesterday();

        $this->post(self::IMPORT_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "date,menu_code,quantity,final_status\n"
                . "{$date},SUS-001,4,sold\n"
                . "{$date},NOPE-9,4,sold\n"
            ),
        ])->assertStatus(422);

        $this->assertDatabaseCount('production_items', 0);
    }

    /**
     * Idempotensi lewat `X-Client-Request-Id` tidak menolong di sini: unggahan
     * kedua adalah aksi baru dengan id baru. Yang menahannya harus datanya.
     */
    public function test_re_uploading_the_same_file_is_refused_unless_duplicates_are_allowed(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $date = $this->yesterday();
        $body = "date,menu_code,quantity,final_status\n{$date},SUS-001,4,sold\n";

        $this->post(self::IMPORT_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv($body),
        ])->assertOk();

        $this->post(self::IMPORT_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv($body),
        ])->assertStatus(422);

        $this->assertDatabaseCount('production_items', 4);

        $this->post(self::IMPORT_URL, [
            'outletId'       => $outlet->id,
            'allowDuplicate' => '1',
            'file'           => $this->csv($body),
        ])->assertOk();

        $this->assertDatabaseCount('production_items', 8);
    }

    public function test_preview_counts_plates_that_already_exist_for_that_menu_and_day(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu(null, ['code' => 'SUS-001']);

        $this->createProductionItem($outlet, $menu, [
            'produced_at'  => now()->subDay()->setTime(10, 0),
            'final_status' => 'sold',
        ]);

        $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "date,menu_code,quantity,final_status\n{$this->yesterday()},SUS-001,4,sold\n"
            ),
        ])
            ->assertOk()
            ->assertJsonPath('data.rows.0.existingPlates', 1)
            ->assertJsonPath('data.summary.duplicatePlates', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | BRAND & AKSES
    |--------------------------------------------------------------------------
    */

    /**
     * `menus.code` unik di dalam brand, bukan global. Peta kode yang tidak
     * disaring brand akan menempelkan piring ke menu brand lain — dengan warna,
     * dan karenanya harga, yang salah.
     */
    public function test_a_menu_code_from_another_brand_is_not_resolvable(): void
    {
        $this->actingAsRole('admin');

        $outlet = $this->createOutlet();

        $otherBrand      = $this->createBrand(['code' => 'OTH', 'name' => 'Other']);
        $otherPlateColor = $this->createPlateColor([
            'platename' => 'Biru',
            'brand_id'  => $otherBrand->id,
        ]);
        $this->createMenu($otherPlateColor, [
            'code'     => 'SUS-001',
            'menuname' => 'Menu Brand Lain',
        ]);

        $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->csv(
                "date,menu_code,quantity,final_status\n{$this->yesterday()},SUS-001,4,sold\n"
            ),
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.errorRows', 1)
            ->assertJsonPath('data.rows.0.menuName', null);
    }

    public function test_only_admin_may_import(): void
    {
        $outlet = Outlet::create([
            'code'      => 'BDG',
            'name'      => 'Bandung',
            'brand'     => 'Maharasa',
            'is_active' => true,
        ]);

        foreach (['kitchen', 'operation', 'production'] as $role) {
            $this->actingAsRole($role);

            $this->post(self::PREVIEW_URL, [
                'outletId' => $outlet->id,
                'file'     => $this->csv("date,menu_code,quantity,final_status\n"),
            ])->assertStatus(403);

            $this->post(self::IMPORT_URL, [
                'outletId' => $outlet->id,
                'file'     => $this->csv("date,menu_code,quantity,final_status\n"),
            ])->assertStatus(403);
        }
    }
}
