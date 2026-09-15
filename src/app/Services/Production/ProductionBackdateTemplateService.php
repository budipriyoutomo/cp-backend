<?php

namespace App\Services\Production;

use App\Models\Menu;
use App\Models\Outlet;
use App\Services\Concerns\ResolvesOutletBrand;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Template impor produksi backdate, dalam bentuk workbook .xlsx.
 *
 * Dibuat di server, bukan di browser, karena isinya dua hal yang keduanya hanya
 * ada di sini: **menu aktif brand outlet itu** dan **aturan yang benar-benar
 * dijalankan importer**. Template statis di frontend akan menyimpang diam-diam
 * begitu batas di ProductionBackdateImportService berubah — operator membaca
 * satu aturan, server menjalankan aturan lain, dan yang terlihat hanya "baris
 * tidak valid" tanpa sebab.
 *
 * Tiga sheet, masing-masing satu tugas:
 *
 * - **Import Produksi** — satu-satunya sheet yang dibaca importer. Sudah berisi
 *   satu baris per menu aktif, tinggal diisi tanggal, jumlah, dan status.
 * - **Panduan Pengisian** — aturan per kolom, dirakit dari konstanta importer.
 * - **Daftar Menu Aktif** — referensi kode menu, warna piring, dan harganya.
 *
 * Baris yang tanggal, jumlah, dan statusnya sama-sama kosong dilewati importer
 * (lihat `ProductionBackdateImportService::isUnfilledRow()`), jadi operator
 * cukup mengisi menu yang dipakai dan membiarkan sisanya.
 */
class ProductionBackdateTemplateService
{
    use ResolvesOutletBrand;

    public const DATA_SHEET  = 'Import Produksi';
    public const GUIDE_SHEET = 'Panduan Pengisian';
    public const MENU_SHEET  = 'Daftar Menu Aktif';

    /**
     * Baris kosong ekstra di bawah daftar menu: satu menu boleh diproduksi di
     * beberapa tanggal atau berakhir dua status sekaligus (sold + waste), jadi
     * satu baris per menu tidak selalu cukup. Format dan dropdownnya tetap
     * terpasang sampai baris ini.
     */
    private const SPARE_ROWS = 100;

    private const HEADER_FILL = 'FF1F3864';
    private const REF_FILL    = 'FFF2F2F2';

    /**
     * @return array{filename: string, contents: string}
     */
    public function build(string $outletId): array
    {
        $brandId = $this->brandIdForOutlet($outletId);
        $outlet  = Outlet::find($outletId);
        $menus   = $this->activeMenus($brandId);

        $spreadsheet = new Spreadsheet();

        $spreadsheet->getProperties()
            ->setCreator('Maharasa Colorplate')
            ->setTitle('Template Import Produksi Backdate')
            ->setDescription('Outlet ' . ($outlet?->name ?? '-') . ' — dibuat ' . now()->toDateTimeString());

        $this->buildDataSheet($spreadsheet->getActiveSheet(), $menus);
        $this->buildGuideSheet($spreadsheet->createSheet(), $outlet, $menus);
        $this->buildMenuSheet($spreadsheet->createSheet(), $menus);

        $spreadsheet->setActiveSheetIndex(0);

        return [
            'filename' => $this->filename($outlet),
            'contents' => $this->render($spreadsheet),
        ];
    }

    /**
     * Menu yang bisa dipakai importer: aktif, punya kode, dan brand-nya cocok
     * dengan outlet. Menu tanpa `code` sengaja tidak ikut — importer mencocokkan
     * baris lewat kode, jadi menu tanpa kode tidak punya cara masuk sama sekali.
     *
     * `scopeToBrand()` ikut membawa baris ber-`brand_id` NULL, sama seperti
     * importer. Kalau di sini lebih ketat, template akan menyembunyikan menu
     * yang sebenarnya diterima — dan sebaliknya.
     *
     * @return Collection<int, Menu>
     */
    private function activeMenus(?string $brandId): Collection
    {
        $query = Menu::with(['plateColor', 'brand'])
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('code');

        return $this->scopeToBrand($query, $brandId)->get();
    }

    /*
    |--------------------------------------------------------------------------
    | SHEET 1 — DATA
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, Menu>  $menus
     */
    private function buildDataSheet(Worksheet $sheet, Collection $menus): void
    {
        $sheet->setTitle(self::DATA_SHEET);

        $columns = $this->columns();

        foreach ($columns as $index => $column) {
            $letter = $this->letter($index);

            $sheet->setCellValue($letter . '1', $column['key']);
            $sheet->getColumnDimension($letter)->setWidth($column['width']);
        }

        $lastColumn = $this->letter(count($columns) - 1);

        $this->styleHeader($sheet, "A1:{$lastColumn}1");

        // Menu yang belum punya plate color tidak ikut: warna piring adalah unit
        // harganya, dan importer menolak barisnya. Menaruhnya di sini hanya
        // memberi operator baris yang pasti gagal. Sheet "Daftar Menu Aktif"
        // tetap menampilkannya, lengkap dengan sebabnya.
        $importable = $menus->filter(fn (Menu $menu) => (bool) $menu->plate_color_id)->values();

        $row = 2;

        foreach ($importable as $menu) {
            // Kode menu ditulis eksplisit sebagai teks. Kode seperti "001"
            // kehilangan nol depannya kalau Excel menebaknya sebagai angka, dan
            // importer mencocokkan kode itu apa adanya.
            $sheet->setCellValueExplicit('B' . $row, (string) $menu->code, DataType::TYPE_STRING);
            $sheet->setCellValue('G' . $row, (string) $menu->menuname);
            $sheet->setCellValue('H' . $row, (string) ($menu->plateColor?->platename ?? '-'));

            $row++;
        }

        $lastRow = max($row - 1, 2) + self::SPARE_ROWS;

        $this->styleDataRows($sheet, $lastRow, $lastColumn);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->setSelectedCell('A2');
    }

    private function styleDataRows(Worksheet $sheet, int $lastRow, string $lastColumn): void
    {
        // Format kolom dipasang sampai baris cadangan supaya tanggal yang
        // diketik operator tersimpan sebagai tanggal, bukan teks dengan urutan
        // hari/bulan menurut setelan regional mesinnya.
        $sheet->getStyle("A2:A{$lastRow}")
            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle("C2:C{$lastRow}")
            ->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle("E2:E{$lastRow}")
            ->getNumberFormat()->setFormatCode('hh:mm');

        // Kolom referensi: abu-abu dan miring, supaya terbaca sebagai bantuan
        // dan bukan isian. Importer mengabaikan kolom yang bukan nama kanonik.
        $sheet->getStyle("G2:H{$lastRow}")->applyFromArray([
            'font' => ['italic' => true, 'color' => ['argb' => 'FF808080']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::REF_FILL]],
        ]);

        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowInputMessage(true)
            ->setShowErrorMessage(true)
            ->setShowDropDown(true)
            ->setErrorTitle('Status tidak dikenal')
            ->setError('Isi sold atau waste.')
            ->setPromptTitle('final_status')
            ->setPrompt('sold = terjual, waste = terbuang.')
            ->setFormula1('"sold,waste"');

        $sheet->setDataValidation("D2:D{$lastRow}", $validation);
    }

    /*
    |--------------------------------------------------------------------------
    | SHEET 2 — PANDUAN
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, Menu>  $menus
     */
    private function buildGuideSheet(Worksheet $sheet, ?Outlet $outlet, Collection $menus): void
    {
        $sheet->setTitle(self::GUIDE_SHEET);

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(78);

        $row = 1;

        $row = $this->writeTitle($sheet, $row, 'Panduan Pengisian Template Import Produksi Backdate');
        $row = $this->writeMeta($sheet, $row, $outlet, $menus);
        $row = $this->writeSteps($sheet, $row);
        $row = $this->writeColumnTable($sheet, $row);
        $row = $this->writeRules($sheet, $row);

        $this->writeAliases($sheet, $row);
    }

    private function writeTitle(Worksheet $sheet, int $row, string $title): int
    {
        $sheet->setCellValue('A' . $row, $title);
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->getStyle('A' . $row)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(26);

        return $row + 2;
    }

    /**
     * @param  Collection<int, Menu>  $menus
     */
    private function writeMeta(Worksheet $sheet, int $row, ?Outlet $outlet, Collection $menus): int
    {
        $withoutColor = $menus->filter(fn (Menu $menu) => ! $menu->plateColor)->count();

        $meta = [
            ['Outlet', ($outlet?->name ?? '-') . ' (' . ($outlet?->code ?? '-') . ')'],
            ['Brand', (string) ($menus->first()?->brand?->name ?? $outlet?->brand ?? '-')],
            ['Template dibuat', now()->toDateTimeString()],
            [
                'Menu aktif',
                $menus->count() . ' menu'
                    . ($withoutColor > 0
                        ? " ({$withoutColor} plate color-nya tidak ditemukan, lihat sheet \""
                            . self::MENU_SHEET . '")'
                        : ''),
            ],
            [
                'Berlaku untuk',
                'Outlet di atas saja. Menu dan harganya milik brand outlet ini, jadi template '
                    . 'satu outlet tidak bisa dipakai untuk outlet brand lain.',
            ],
        ];

        foreach ($meta as [$label, $value]) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $sheet->mergeCells("B{$row}:E{$row}");
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $sheet->getStyle('B' . $row)->getAlignment()->setWrapText(true);

            $row++;
        }

        return $row + 1;
    }

    private function writeSteps(Worksheet $sheet, int $row): int
    {
        $row = $this->writeSectionHeading($sheet, $row, 'Langkah');

        $steps = [
            'Isi sheet "' . self::DATA_SHEET . '". Kode menu sudah terisi — tinggal isi date, quantity, dan final_status.',
            'Baris menu yang tidak diproduksi biarkan kosong. Baris yang date, quantity, dan final_status-nya sama-sama kosong dilewati, bukan dianggap error.',
            'Satu menu boleh ditulis beberapa baris: beda tanggal, atau sold dan waste dipisah.',
            'Simpan sebagai .xlsx. Boleh juga .csv, tapi CSV hanya membawa satu sheet — pastikan yang tersimpan sheet "' . self::DATA_SHEET . '".',
            'Unggah di halaman Import Produksi Backdate, klik Preview, periksa ringkasannya, baru Import.',
        ];

        foreach ($steps as $index => $step) {
            $sheet->setCellValue('A' . $row, ($index + 1) . '.');
            $sheet->setCellValue('B' . $row, $step);
            $sheet->mergeCells("B{$row}:E{$row}");
            $sheet->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            $row++;
        }

        return $row + 1;
    }

    private function writeColumnTable(Worksheet $sheet, int $row): int
    {
        $row = $this->writeSectionHeading($sheet, $row, 'Kolom');

        foreach (['Kolom', 'Wajib', 'Format', 'Contoh', 'Aturan'] as $index => $label) {
            $sheet->setCellValue($this->letter($index) . $row, $label);
        }

        $this->styleHeader($sheet, "A{$row}:E{$row}");
        $row++;

        foreach ($this->columns() as $column) {
            $sheet->setCellValue('A' . $row, $column['key']);
            $sheet->setCellValue('B' . $row, $column['required'] ? 'Wajib' : 'Opsional');
            $sheet->setCellValue('C' . $row, $column['format']);
            $sheet->setCellValueExplicit('D' . $row, $column['example'], DataType::TYPE_STRING);
            $sheet->setCellValue('E' . $row, $column['rule']);

            $sheet->getStyle("A{$row}:E{$row}")->getAlignment()
                ->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);

            $row++;
        }

        return $row + 1;
    }

    private function writeRules(Worksheet $sheet, int $row): int
    {
        $row = $this->writeSectionHeading($sheet, $row, 'Aturan penting');

        $rules = [
            'Hanya tanggal sebelum hari ini. Produksi hari berjalan dicatat lewat layar dapur supaya belt dan finalisasinya tetap jalan.',
            'Tanggal paling tua ' . ProductionBackdateImportService::MAX_AGE_DAYS . ' hari ke belakang.',
            'Setiap baris harus punya final_status. Piring backdate tidak bisa difinalisasi belakangan — itu justru keadaan yang bikin impor ini ada.',
            'Batas per berkas: quantity maksimal ' . ProductionBackdateImportService::MAX_QUANTITY_PER_ROW
                . ' per baris, ' . ProductionBackdateImportService::MAX_ROWS . ' baris data, dan '
                . ProductionBackdateImportService::MAX_PLATES . ' piring. Lebih dari itu, pecah per periode.',
            'Satu piring = satu baris di database. quantity 12 berarti 12 piring, bukan satu baris berjumlah 12.',
            'Waktu dicatat ke tanggal di kolom date, bukan tanggal impor dijalankan. Laporan hari itu ikut berubah.',
            'Baris waste otomatis membuat waste record, dengan alasan dari kolom notes (atau "Import produksi backdate" kalau kosong).',
            'Impor berjalan utuh atau tidak sama sekali. Satu baris salah membatalkan seluruh berkas — perbaiki lalu preview ulang.',
            'Berkas yang sama diunggah dua kali menghasilkan piring dua kali lipat. Preview memberi tahu kalau menu dan tanggal itu sudah punya piring; teruskan hanya kalau memang mau menambah di atasnya.',
            'Kode menu diresolusi di dalam brand outlet. Kode yang sama bisa menunjuk menu berbeda di brand lain, jadi outlet yang dipilih saat mengunggah harus sama dengan outlet template ini.',
        ];

        return $this->writeBullets($sheet, $row, $rules);
    }

    private function writeAliases(Worksheet $sheet, int $row): int
    {
        $row = $this->writeSectionHeading($sheet, $row, 'Yang juga diterima');

        $grouped = [];

        foreach (ProductionBackdateImportService::HEADER_ALIASES as $alias => $canonical) {
            $grouped[$canonical][] = $alias;
        }

        $lines = [];

        foreach ($grouped as $canonical => $aliases) {
            $lines[] = 'Judul kolom "' . $canonical . '" boleh ditulis: ' . implode(', ', $aliases) . '.';
        }

        $lines[] = 'final_status boleh ditulis: '
            . implode(', ', array_keys(ProductionBackdateImportService::STATUS_ALIASES)) . '.';
        $lines[] = 'Tanggal boleh YYYY-MM-DD, DD/MM/YYYY, atau DD-MM-YYYY. Sel bertipe tanggal di Excel juga terbaca.';
        $lines[] = 'Urutan kolom bebas, dan kolom tambahan (seperti ref_menu_name) diabaikan.';

        return $this->writeBullets($sheet, $row, $lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function writeBullets(Worksheet $sheet, int $row, array $lines): int
    {
        foreach ($lines as $line) {
            $sheet->setCellValue('A' . $row, '-');
            $sheet->setCellValue('B' . $row, $line);
            $sheet->mergeCells("B{$row}:E{$row}");
            $sheet->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            $row++;
        }

        return $row + 1;
    }

    private function writeSectionHeading(Worksheet $sheet, int $row, string $title): int
    {
        $sheet->setCellValue('A' . $row, $title);
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->getStyle('A' . $row)->applyFromArray([
            'font'    => ['bold' => true, 'size' => 12],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        return $row + 1;
    }

    /*
    |--------------------------------------------------------------------------
    | SHEET 3 — DAFTAR MENU
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, Menu>  $menus
     */
    private function buildMenuSheet(Worksheet $sheet, Collection $menus): void
    {
        $sheet->setTitle(self::MENU_SHEET);

        $headers = [
            ['Kode Menu', 18],
            ['Nama Menu', 34],
            ['Plate Color', 18],
            ['Harga Piring', 16],
            ['Shelf Life (menit)', 18],
            ['Catatan', 46],
        ];

        foreach ($headers as $index => [$label, $width]) {
            $letter = $this->letter($index);

            $sheet->setCellValue($letter . '1', $label);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }

        $this->styleHeader($sheet, 'A1:F1');

        $row = 2;

        foreach ($menus as $menu) {
            $sheet->setCellValueExplicit('A' . $row, (string) $menu->code, DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, (string) $menu->menuname);
            $sheet->setCellValue('C' . $row, (string) ($menu->plateColor?->platename ?? '-'));
            // Harga yang direkonsiliasi POS adalah harga warna piring, bukan
            // harga menu — satu warna satu harga.
            $sheet->setCellValue('D' . $row, $menu->plateColor?->price);
            $sheet->setCellValue('E' . $row, $menu->shelf_life);
            // Warna piring yang sudah dihapus (soft delete) meninggalkan menu
            // dengan `plate_color_id` yang tidak menunjuk baris mana pun. Menu
            // begitu masih bisa diimpor — piringnya tetap terhubung ke id lama —
            // tapi rekonsiliasi POS-nya tidak akan punya harga, dan itu tidak
            // terlihat di layar mana pun kecuali disebut di sini.
            $sheet->setCellValue('F' . $row, $menu->plateColor
                ? ''
                : 'Plate color tidak ditemukan — kemungkinan sudah dihapus. Perbaiki di master menu.');

            $row++;
        }

        $lastRow = max($row - 1, 2);

        $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle("F2:F{$lastRow}")->getFont()->getColor()->setARGB('FFC00000');

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:F{$lastRow}");
    }

    /*
    |--------------------------------------------------------------------------
    | SPEC KOLOM
    |--------------------------------------------------------------------------
    */

    /**
     * Satu sumber untuk header sheet data dan tabel di sheet panduan — kalau
     * keduanya ditulis terpisah, panduannya akan menjelaskan kolom yang tidak
     * ada di templatenya sendiri.
     *
     * @return array<int, array{key: string, required: bool, format: string, example: string, rule: string, width: int}>
     */
    private function columns(): array
    {
        return [
            [
                'key'      => 'date',
                'required' => true,
                'format'   => 'YYYY-MM-DD',
                'example'  => '2026-01-15',
                'rule'     => 'Tanggal produksi. Harus sebelum hari ini dan maksimal '
                    . ProductionBackdateImportService::MAX_AGE_DAYS . ' hari ke belakang.',
                'width'    => 14,
            ],
            [
                'key'      => 'menu_code',
                'required' => true,
                'format'   => 'Teks',
                'example'  => 'SUS-001',
                'rule'     => 'Kode menu dari sheet "' . self::MENU_SHEET . '". Sudah terisi di template; '
                    . 'jangan diganti kecuali menunya memang berbeda.',
                'width'    => 16,
            ],
            [
                'key'      => 'quantity',
                'required' => true,
                'format'   => 'Bilangan bulat',
                'example'  => '12',
                'rule'     => 'Jumlah piring. Minimal 1, maksimal '
                    . ProductionBackdateImportService::MAX_QUANTITY_PER_ROW . ' per baris.',
                'width'    => 11,
            ],
            [
                'key'      => 'final_status',
                'required' => true,
                'format'   => 'sold / waste',
                'example'  => 'sold',
                'rule'     => 'Nasib akhir piring. sold = terjual, waste = terbuang. Tidak boleh kosong — '
                    . 'piring backdate tidak bisa difinalisasi belakangan.',
                'width'    => 14,
            ],
            [
                'key'      => 'time',
                'required' => false,
                'format'   => 'HH:MM',
                'example'  => '09:30',
                'rule'     => 'Jam produksi. Kosong berarti ' . ProductionBackdateImportService::DEFAULT_TIME
                    . '. Tidak mengubah tanggal laporan, hanya jam yang tercatat.',
                'width'    => 10,
            ],
            [
                'key'      => 'notes',
                'required' => false,
                'format'   => 'Teks (maks 255)',
                'example'  => 'rusak saat plating',
                'rule'     => 'Catatan. Untuk baris waste, isi ini jadi alasan waste di laporan.',
                'width'    => 30,
            ],
            [
                'key'      => 'ref_menu_name',
                'required' => false,
                'format'   => 'Referensi',
                'example'  => 'Salmon Nigiri',
                'rule'     => 'Kolom bantu, tidak dibaca sistem. Ada supaya kode menu tidak perlu dihafal.',
                'width'    => 28,
            ],
            [
                'key'      => 'ref_plate_color',
                'required' => false,
                'format'   => 'Referensi',
                'example'  => 'Merah',
                'rule'     => 'Kolom bantu, tidak dibaca sistem.',
                'width'    => 16,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UTIL
    |--------------------------------------------------------------------------
    */

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index + 1);
    }

    private function filename(?Outlet $outlet): string
    {
        $code = strtolower((string) ($outlet?->code ?? 'outlet'));
        $code = trim((string) preg_replace('/[^a-z0-9]+/', '-', $code), '-');

        return 'template-import-produksi-' . ($code ?: 'outlet') . '-' . Carbon::now()->format('Ymd') . '.xlsx';
    }

    /**
     * Workbook ditulis ke berkas sementara lalu dibaca balik: writer Xlsx
     * merakit sebuah zip, dan zip tidak bisa ditulis ke stream memori.
     */
    private function render(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl');

        if ($path === false) {
            throw new \RuntimeException('Tidak bisa membuat berkas sementara untuk template.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException('Template gagal ditulis.');
            }

            return $contents;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }
}
