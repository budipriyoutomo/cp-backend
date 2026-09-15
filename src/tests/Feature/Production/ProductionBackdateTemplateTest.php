<?php

namespace Tests\Feature\Production;

use App\Models\Menu;
use App\Services\Production\ProductionBackdateImportService;
use App\Services\Production\ProductionBackdateTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Template impor produksi backdate.
 *
 * Yang dijaga di sini bukan tampilannya, tapi dua hal yang kalau salah tidak
 * memunculkan error apa pun: template menampilkan menu brand yang salah, dan
 * berkas hasil isiannya ditolak importer sendiri. Karena itu ada satu test
 * round-trip penuh — template diunduh, diisi seperti operator mengisinya, lalu
 * diimpor.
 */
class ProductionBackdateTemplateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private const TEMPLATE_URL = '/api/production/import-backdate/template';
    private const PREVIEW_URL  = '/api/production/import-backdate/preview';
    private const IMPORT_URL   = '/api/production/import-backdate';

    private function download(string $outletId): Spreadsheet
    {
        $response = $this->get(self::TEMPLATE_URL . '?outletId=' . $outletId);

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $response->getContent());

        $spreadsheet = IOFactory::load($path);

        @unlink($path);

        return $spreadsheet;
    }

    /**
     * Kolom -> huruf, dibaca dari header sheet data. Test tidak boleh bergantung
     * pada urutan kolom: urutannya boleh berubah, yang tidak boleh berubah
     * adalah kolomnya ada dan terbaca importer.
     *
     * @return array<string, string>
     */
    private function columnsOf(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $columns = [];

        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = trim((string) $cell->getValue());

                if ($value !== '') {
                    $columns[$value] = $cell->getColumn();
                }
            }
        }

        return $columns;
    }

    /*
    |--------------------------------------------------------------------------
    | ISI TEMPLATE
    |--------------------------------------------------------------------------
    */

    public function test_template_lists_every_active_menu_of_the_outlet_brand(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();

        $color = $this->createPlateColor();
        $this->createMenu($color, ['code' => 'SUS-001', 'menuname' => 'Salmon Nigiri']);
        $this->createMenu($color, ['code' => 'SUS-002', 'menuname' => 'Tuna Nigiri']);

        $spreadsheet = $this->download($outlet->id);

        $this->assertSame(
            [
                ProductionBackdateTemplateService::DATA_SHEET,
                ProductionBackdateTemplateService::GUIDE_SHEET,
                ProductionBackdateTemplateService::MENU_SHEET,
            ],
            $spreadsheet->getSheetNames()
        );

        $sheet   = $spreadsheet->getSheetByName(ProductionBackdateTemplateService::DATA_SHEET);
        $columns = $this->columnsOf($sheet);

        foreach (ProductionBackdateImportService::REQUIRED_HEADERS as $required) {
            $this->assertArrayHasKey($required, $columns, "Kolom wajib {$required} hilang dari template.");
        }

        $codes = [
            $sheet->getCell($columns['menu_code'] . '2')->getValue(),
            $sheet->getCell($columns['menu_code'] . '3')->getValue(),
        ];

        sort($codes);

        $this->assertSame(['SUS-001', 'SUS-002'], $codes);

        // Kolom isian dibiarkan kosong: operator mengisi yang diproduksi saja.
        $this->assertSame('', (string) $sheet->getCell($columns['quantity'] . '2')->getValue());
        $this->assertSame('', (string) $sheet->getCell($columns['date'] . '2')->getValue());
    }

    public function test_template_leaves_out_inactive_menus_and_menus_of_other_brands(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();

        $color = $this->createPlateColor();
        $this->createMenu($color, ['code' => 'AKTIF', 'menuname' => 'Salmon']);
        $this->createMenu($color, ['code' => 'NONAKTIF', 'menuname' => 'Musiman', 'is_active' => false]);

        $otherBrand = $this->createBrand(['code' => 'OTH', 'name' => 'Brand Lain']);
        $otherColor = $this->createPlateColor(['platename' => 'Biru', 'brand_id' => $otherBrand->id]);
        $this->createMenu($otherColor, ['code' => 'LAIN', 'menuname' => 'Punya Brand Lain']);

        $spreadsheet = $this->download($outlet->id);
        $sheet       = $spreadsheet->getSheetByName(ProductionBackdateTemplateService::DATA_SHEET);
        $columns     = $this->columnsOf($sheet);

        $codes = [];

        for ($row = 2; $row <= 10; $row++) {
            $code = trim((string) $sheet->getCell($columns['menu_code'] . $row)->getValue());

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $this->assertSame(['AKTIF'], $codes);
    }

    public function test_guide_sheet_states_the_limits_the_importer_actually_enforces(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $spreadsheet = $this->download($outlet->id);
        $guide       = $spreadsheet->getSheetByName(ProductionBackdateTemplateService::GUIDE_SHEET);

        $text = '';

        foreach ($guide->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $text .= ' ' . $cell->getValue();
            }
        }

        // Angka di panduan dirakit dari konstanta importer. Kalau batasnya
        // berubah tanpa panduannya ikut, test ini yang memberi tahu.
        $this->assertStringContainsString((string) ProductionBackdateImportService::MAX_AGE_DAYS, $text);
        $this->assertStringContainsString((string) ProductionBackdateImportService::MAX_ROWS, $text);
        $this->assertStringContainsString((string) ProductionBackdateImportService::MAX_PLATES, $text);
        $this->assertStringContainsString(ProductionBackdateImportService::DEFAULT_TIME, $text);
        $this->assertStringContainsString($outlet->name, $text);
    }

    public function test_menu_sheet_flags_a_menu_whose_plate_color_was_deleted(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();

        $color = $this->createPlateColor();
        $this->createMenu($color, ['code' => 'AAA-UTUH', 'menuname' => 'Salmon Nigiri']);

        $deleted = $this->createPlateColor(['platename' => 'Kuning']);
        $this->createMenu($deleted, ['code' => 'ZZZ-YATIM', 'menuname' => 'Tuna Nigiri']);
        // Soft delete, jadi `plate_color_id` menu tetap terisi tapi tidak
        // menunjuk baris mana pun — satu-satunya cara keadaan ini muncul, karena
        // kolomnya NOT NULL.
        $deleted->delete();

        $spreadsheet = $this->download($outlet->id);
        $menuSheet   = $spreadsheet->getSheetByName(ProductionBackdateTemplateService::MENU_SHEET);

        $this->assertSame('AAA-UTUH', $menuSheet->getCell('A2')->getValue());
        $this->assertSame('', (string) $menuSheet->getCell('F2')->getValue());

        $this->assertSame('ZZZ-YATIM', $menuSheet->getCell('A3')->getValue());
        $this->assertStringContainsString(
            'Plate color tidak ditemukan',
            (string) $menuSheet->getCell('F3')->getValue()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AKSES
    |--------------------------------------------------------------------------
    */

    public function test_template_is_closed_to_non_admin_roles(): void
    {
        $this->actingAsRole('kitchen');
        $outlet = $this->createOutlet();

        $this->get(self::TEMPLATE_URL . '?outletId=' . $outlet->id)->assertForbidden();
    }

    public function test_template_needs_an_outlet(): void
    {
        $this->actingAsRole('admin');

        $this->get(self::TEMPLATE_URL)->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | ROUND TRIP
    |--------------------------------------------------------------------------
    */

    public function test_filled_template_is_accepted_by_the_importer(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();

        $color = $this->createPlateColor();
        $this->createMenu($color, ['code' => 'SUS-001', 'menuname' => 'Salmon Nigiri']);
        $this->createMenu($color, ['code' => 'SUS-002', 'menuname' => 'Tuna Nigiri']);

        $date        = now()->subDay()->toDateString();
        $spreadsheet = $this->download($outlet->id);
        $sheet       = $spreadsheet->getSheetByName(ProductionBackdateTemplateService::DATA_SHEET);
        $columns     = $this->columnsOf($sheet);

        // Operator mengisi satu baris saja dan membiarkan baris menu lainnya
        // kosong — bentuk pemakaian yang paling umum.
        $target = trim((string) $sheet->getCell($columns['menu_code'] . '2')->getValue()) === 'SUS-001' ? 2 : 3;

        $sheet->setCellValue($columns['date'] . $target, $date);
        $sheet->setCellValue($columns['quantity'] . $target, 12);
        $sheet->setCellValue($columns['final_status'] . $target, 'sold');

        $upload = $this->xlsxUpload($spreadsheet);

        $this->post(self::PREVIEW_URL, ['outletId' => $outlet->id, 'file' => $upload])
            ->assertOk()
            ->assertJsonPath('data.summary.totalRows', 1)
            ->assertJsonPath('data.summary.errorRows', 0)
            ->assertJsonPath('data.summary.totalPlates', 12)
            ->assertJsonPath('data.summary.soldPlates', 12)
            ->assertJsonPath('data.rows.0.menuCode', 'SUS-001')
            ->assertJsonPath('data.rows.0.date', $date);

        $this->post(self::IMPORT_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->xlsxUpload($spreadsheet),
        ])->assertOk()->assertJsonPath('data.imported', 12);

        $this->assertDatabaseCount('production_items', 12);
    }

    public function test_dates_typed_as_excel_dates_are_read_as_dates(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $date = now()->subDays(3)->startOfDay();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $sheet->fromArray(['date', 'menu_code', 'quantity', 'final_status', 'time'], null, 'A1');
        $sheet->fromArray(['', 'SUS-001', 5, 'waste', ''], null, 'A2');

        // Sel tanggal Excel: bilangan + format tanggal, persis yang dihasilkan
        // Excel saat operator mengetik tanggal di kolom bertipe tanggal.
        $sheet->setCellValue('A2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        // Jam sebagai pecahan hari (09:30).
        $sheet->setCellValue('E2', 9.5 / 24);
        $sheet->getStyle('E2')->getNumberFormat()->setFormatCode('hh:mm');

        $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->xlsxUpload($spreadsheet),
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.errorRows', 0)
            ->assertJsonPath('data.rows.0.date', $date->toDateString())
            ->assertJsonPath('data.rows.0.producedAt', $date->copy()->setTime(9, 30)->toDateTimeString());
    }

    public function test_rows_left_untouched_in_the_template_are_skipped_instead_of_failing(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $color = $this->createPlateColor();
        $this->createMenu($color, ['code' => 'SUS-001', 'menuname' => 'Salmon Nigiri']);
        $this->createMenu($color, ['code' => 'SUS-002', 'menuname' => 'Tuna Nigiri']);

        $date = now()->subDay()->toDateString();

        // Baris kedua hanya membawa kode menu — tepat seperti template yang
        // menunya tidak diproduksi hari itu.
        $file = UploadedFile::fake()->createWithContent(
            'backdate.csv',
            "date,menu_code,quantity,final_status\n"
            . "{$date},SUS-001,4,sold\n"
            . ",SUS-002,,\n"
        );

        $this->post(self::PREVIEW_URL, ['outletId' => $outlet->id, 'file' => $file])
            ->assertOk()
            ->assertJsonPath('data.summary.totalRows', 1)
            ->assertJsonPath('data.summary.errorRows', 0)
            ->assertJsonPath('data.summary.totalPlates', 4);
    }

    public function test_a_workbook_uploaded_while_another_sheet_is_active_still_imports_the_data_sheet(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $this->createMenu(null, ['code' => 'SUS-001']);

        $date        = now()->subDay()->toDateString();
        $spreadsheet = $this->download($outlet->id);
        $sheet       = $spreadsheet->getSheetByName(ProductionBackdateTemplateService::DATA_SHEET);
        $columns     = $this->columnsOf($sheet);

        $sheet->setCellValue($columns['date'] . '2', $date);
        $sheet->setCellValue($columns['quantity'] . '2', 3);
        $sheet->setCellValue($columns['final_status'] . '2', 'waste');

        // Operator menutup berkas sambil membuka sheet panduan.
        $spreadsheet->setActiveSheetIndexByName(ProductionBackdateTemplateService::GUIDE_SHEET);

        $this->post(self::PREVIEW_URL, [
            'outletId' => $outlet->id,
            'file'     => $this->xlsxUpload($spreadsheet),
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.totalRows', 1)
            ->assertJsonPath('data.summary.wastePlates', 3);
    }

    private function xlsxUpload(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl') . '.xlsx';

        (new Xlsx($spreadsheet))->save($path);

        $file = UploadedFile::fake()->createWithContent('isian.xlsx', file_get_contents($path));

        @unlink($path);

        return $file;
    }
}
