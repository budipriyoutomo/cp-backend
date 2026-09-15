<?php

namespace App\Services\Production;

use App\Exceptions\BusinessRuleException;
use App\Models\Menu;
use App\Models\ProductionItem;
use App\Models\WasteRecord;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesOutletBrand;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Import produksi hari lalu dari berkas .xlsx atau CSV.
 *
 * Alasan modul ini ada: piring hanya bisa difinalisasi pada hari produksinya
 * (`ProductionItemService::assertWithinProductionDay()`), dan `production:close-stale`
 * menutup sisa hari kemarin jadi waste. Artinya begitu satu hari lewat, tidak
 * ada jalan sah untuk memasukkan produksi yang luput dicatat — dapur yang mati
 * listrik atau tabletnya rusak kehilangan harinya. Ini jalur itu, dan sengaja
 * dikunci ke `role:admin`.
 *
 * Aturan yang dipegang di sini:
 *
 * - **Hanya masa lalu.** Tanggal hari ini dan seterusnya ditolak; untuk hari
 *   berjalan pakai layar produksi biasa supaya belt dan finalisasi tetap jalan.
 * - **Selalu final.** Setiap baris membawa `sold` atau `waste`. Baris tanpa
 *   `final_status` akan jadi piring yang tidak bisa ditutup siapa pun — persis
 *   keadaan yang bikin modul ini dibutuhkan.
 * - **Atribusi ke hari produksi.** `sold_at` / `wasted_at` / `recorded_at`
 *   diisi `produced_at`, bukan `now()`. Aturan yang sama dengan
 *   `autoWasteCarryOver()`: laporan hari lalu tidak boleh bergeser karena
 *   impor dijalankan hari ini.
 * - **Brand outlet menentukan menu.** `menus.code` unik di dalam brand, bukan
 *   global, jadi kode yang sama bisa menunjuk dua menu berbeda. Resolusinya
 *   lewat brand outlet, sama seperti POSService.
 */
class ProductionBackdateImportService extends BaseService
{
    use ResolvesOutletBrand;

    protected string $model = ProductionItem::class;

    /** Baris data (di luar header) yang masih mau dibaca dari satu berkas. */
    public const MAX_ROWS = 2000;

    /** Satu piring = satu baris tabel, jadi batasnya di jumlah piring, bukan baris CSV. */
    public const MAX_PLATES = 5000;

    public const MAX_QUANTITY_PER_ROW = 1000;

    /** Tanggal yang lebih tua dari ini hampir selalu salah ketik, bukan backfill. */
    public const MAX_AGE_DAYS = 365;

    /**
     * Jam default `produced_at` kalau kolom `time` tidak diisi.
     *
     * Tengah hari, bukan 00:00: seluruh laporan mengelompokkan per tanggal, tapi
     * jam tengah malam membuat baris hasil impor terlihat seperti sisa hari
     * sebelumnya di layar mana pun yang menampilkan waktu.
     */
    public const DEFAULT_TIME = '12:00';

    public const REQUIRED_HEADERS = ['date', 'menu_code', 'quantity', 'final_status'];

    /**
     * Nama kolom yang diterima selain nama kanoniknya. Berkas ini disusun
     * operator di Excel, dan judul kolomnya berbahasa Indonesia sama seringnya.
     */
    public const HEADER_ALIASES = [
        'tanggal'     => 'date',
        'kode_menu'   => 'menu_code',
        'menucode'    => 'menu_code',
        'kode'        => 'menu_code',
        'qty'         => 'quantity',
        'jumlah'      => 'quantity',
        'status'      => 'final_status',
        'finalstatus' => 'final_status',
        'jam'         => 'time',
        'waktu'       => 'time',
        'catatan'     => 'notes',
        'keterangan'  => 'notes',
    ];

    public const STATUS_ALIASES = [
        'sold'    => 'sold',
        'terjual' => 'sold',
        'jual'    => 'sold',
        'waste'   => 'waste',
        'buang'   => 'waste',
        'terbuang' => 'waste',
    ];

    /**
     * Baca + validasi tanpa menulis apa pun.
     *
     * @return array{summary: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    public function preview(UploadedFile $file, string $outletId): array
    {
        $rows = $this->analyze($file, $outletId);

        return [
            'summary' => $this->summarize($rows),
            'rows'    => array_map([$this, 'presentRow'], $rows),
        ];
    }

    /**
     * Impor sungguhan. Satu transaksi, semua atau tidak sama sekali — separuh
     * hari yang masuk lebih sulit dibereskan daripada impor yang gagal utuh.
     *
     * @return array{imported: int, wasteRecords: int, summary: array<string, mixed>}
     */
    public function import(UploadedFile $file, string $outletId, bool $allowDuplicate = false): array
    {
        $rows    = $this->analyze($file, $outletId);
        $summary = $this->summarize($rows);

        if ($summary['errorRows'] > 0) {
            throw new BusinessRuleException(
                "Impor dibatalkan: {$summary['errorRows']} baris tidak valid. "
                . 'Perbaiki berkas lalu jalankan preview lagi.'
            );
        }

        if ($summary['totalRows'] === 0) {
            throw new BusinessRuleException('Berkas tidak berisi baris data.');
        }

        // Berkas yang sama diunggah dua kali menghasilkan piring dua kali lipat,
        // dan `X-Client-Request-Id` tidak menahannya — unggahan kedua adalah aksi
        // baru dengan id baru. Karena itu tabrakan dinilai dari datanya sendiri.
        if (! $allowDuplicate && $summary['duplicatePlates'] > 0) {
            throw new BusinessRuleException(
                "Outlet ini sudah punya {$summary['duplicatePlates']} piring pada menu dan tanggal "
                . 'yang sama di berkas. Centang "izinkan duplikat" kalau memang mau menambah di atasnya.'
            );
        }

        return DB::transaction(function () use ($rows, $outletId, $summary) {
            $imported = 0;
            $wasted   = 0;

            foreach ($rows as $row) {
                /** @var Menu $menu */
                $menu       = $row['menu'];
                $producedAt = $row['producedAt'];
                $expiresAt  = $producedAt->copy()->addMinutes($menu->shelf_life ?? 60);

                for ($i = 0; $i < $row['quantity']; $i++) {
                    /** @var ProductionItem $item */
                    $item = $this->create([
                        'menu_id'      => $menu->id,
                        'outlet_id'    => $outletId,
                        'plate_color'  => $menu->plate_color_id,
                        'quantity'     => 1,
                        'produced_at'  => $producedAt,
                        'expires_at'   => $expiresAt,
                        // Tanggalnya sudah lewat, jadi tidak ada keadaan belt
                        // lain yang masuk akal — dan tidak ada yang perlu
                        // disegarkan `production:refresh-belt-status`.
                        'belt_status'  => 'expired',
                        'final_status' => $row['finalStatus'],
                        'sold_at'      => $row['finalStatus'] === 'sold' ? $producedAt : null,
                        'wasted_at'    => $row['finalStatus'] === 'waste' ? $producedAt : null,
                        'notes'        => $row['notes'],
                    ]);

                    $imported++;

                    if ($row['finalStatus'] !== 'waste') {
                        continue;
                    }

                    // Laporan waste dibaca dari `waste_records`, bukan dari
                    // production_items — piring waste tanpa record di sini
                    // hilang dari analisis waste. Sama seperti autoWasteCarryOver().
                    WasteRecord::create([
                        'production_item_id' => $item->id,
                        'menu_id'            => $menu->id,
                        'plate_color'        => $menu->plate_color_id,
                        'quantity'           => 1,
                        'reason'             => $row['notes'] ?: 'Import produksi backdate',
                        'recorded_at'        => $producedAt,
                        'outlet_id'          => $outletId,
                    ]);

                    $wasted++;
                }
            }

            return [
                'imported'     => $imported,
                'wasteRecords' => $wasted,
                'summary'      => $summary,
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ANALISIS
    |--------------------------------------------------------------------------
    */

    /**
     * Parse + validasi setiap baris. Baris yang salah tidak melempar — ia
     * membawa daftar `errors`-nya sendiri, supaya preview bisa menampilkan
     * seluruh masalah sekaligus alih-alih satu per unggahan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function analyze(UploadedFile $file, string $outletId): array
    {
        $raw = $this->parseFile($file);

        if ($raw === []) {
            return [];
        }

        $brandId = $this->brandIdForOutlet($outletId);
        $menus   = $this->menusByCode($brandId);
        $today   = Carbon::today();
        $oldest  = $today->copy()->subDays(self::MAX_AGE_DAYS);

        $rows = [];

        foreach ($raw as $entry) {
            $errors = [];

            $date         = $this->parseDate($entry['date'] ?? null, $today, $oldest, $errors);
            $menuCode     = trim((string) ($entry['menu_code'] ?? ''));
            $menu         = $this->resolveMenu($menuCode, $menus, $errors);
            $quantity     = $this->parseQuantity($entry['quantity'] ?? null, $errors);
            $finalStatus  = $this->parseStatus($entry['final_status'] ?? null, $errors);
            $time         = $this->parseTime($entry['time'] ?? null, $errors);

            $producedAt = ($date && $time) ? $date->copy()->setTimeFromTimeString($time) : null;

            $rows[] = [
                'line'           => $entry['line'],
                'date'           => $date?->toDateString(),
                'menuCode'       => $menuCode,
                'menu'           => $menu,
                'quantity'       => $quantity,
                'finalStatus'    => $finalStatus,
                'notes'          => $this->cleanNotes($entry['notes'] ?? null),
                'producedAt'     => $producedAt,
                'existingPlates' => 0,
                'errors'         => $errors,
            ];
        }

        return $this->attachExistingPlates($rows, $outletId);
    }

    /**
     * Berapa piring yang sudah ada untuk (menu, tanggal) tiap baris.
     *
     * Dihitung di PHP dari satu query, bukan lewat GROUP BY per tanggal:
     * pemotongan tanggal berbeda antara SQLite (test) dan PostgreSQL (produksi),
     * dan pola ini sudah dipakai `WasteAnalysisService` dengan alasan yang sama.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachExistingPlates(array $rows, string $outletId): array
    {
        $menuIds = [];
        $dates   = [];

        foreach ($rows as $row) {
            if ($row['menu'] && $row['date']) {
                $menuIds[$row['menu']->id] = true;
                $dates[$row['date']]       = true;
            }
        }

        if ($menuIds === []) {
            return $rows;
        }

        $dateKeys = array_keys($dates);
        sort($dateKeys);

        $existing = ProductionItem::query()
            ->where('outlet_id', $outletId)
            ->whereIn('menu_id', array_keys($menuIds))
            ->whereBetween('produced_at', [
                Carbon::parse($dateKeys[0])->startOfDay(),
                Carbon::parse($dateKeys[count($dateKeys) - 1])->endOfDay(),
            ])
            ->get(['menu_id', 'produced_at']);

        $counts = [];

        foreach ($existing as $item) {
            $key = $item->menu_id . '|' . $item->produced_at->toDateString();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        foreach ($rows as $index => $row) {
            if (! $row['menu'] || ! $row['date']) {
                continue;
            }

            $rows[$index]['existingPlates'] = $counts[$row['menu']->id . '|' . $row['date']] ?? 0;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(array $rows): array
    {
        $valid = array_values(array_filter($rows, fn ($row) => $row['errors'] === []));

        $dates = [];
        foreach ($valid as $row) {
            $dates[$row['date']] = true;
        }
        $dates = array_keys($dates);
        sort($dates);

        $plates = array_sum(array_column($valid, 'quantity'));

        // Duplikat dihitung sekali per (menu, tanggal): dua baris berkas yang
        // menunjuk pasangan yang sama melihat hitungan tersimpan yang sama, dan
        // menjumlahkannya akan melipatgandakan angka yang sama.
        $duplicates = [];
        foreach ($valid as $row) {
            if ($row['existingPlates'] > 0) {
                $duplicates[$row['menu']->id . '|' . $row['date']] = $row['existingPlates'];
            }
        }

        return [
            'totalRows'       => count($rows),
            'validRows'       => count($valid),
            'errorRows'       => count($rows) - count($valid),
            'totalPlates'     => $plates,
            'soldPlates'      => array_sum(array_map(
                fn ($row) => $row['finalStatus'] === 'sold' ? $row['quantity'] : 0,
                $valid
            )),
            'wastePlates'     => array_sum(array_map(
                fn ($row) => $row['finalStatus'] === 'waste' ? $row['quantity'] : 0,
                $valid
            )),
            'dates'           => $dates,
            'duplicatePlates' => array_sum($duplicates),
        ];
    }

    /**
     * Bentuk baris yang dikirim ke klien — tanpa model dan tanpa objek Carbon.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row): array
    {
        /** @var Menu|null $menu */
        $menu = $row['menu'];

        return [
            'line'           => $row['line'],
            'date'           => $row['date'],
            'menuCode'       => $row['menuCode'],
            'menuName'       => $menu?->menuname,
            'plateColorName' => $menu?->plateColor?->platename,
            'quantity'       => $row['quantity'],
            'finalStatus'    => $row['finalStatus'],
            'notes'          => $row['notes'],
            'producedAt'     => $row['producedAt']?->toDateTimeString(),
            'existingPlates' => $row['existingPlates'],
            'errors'         => $row['errors'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI PER FIELD
    |--------------------------------------------------------------------------
    */

    private function parseDate(?string $value, Carbon $today, Carbon $oldest, array &$errors): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            $errors[] = 'Kolom date kosong.';

            return null;
        }

        $date = $this->toDate($value);

        if (! $date) {
            $errors[] = "Tanggal '{$value}' tidak dikenali. Pakai format YYYY-MM-DD atau DD/MM/YYYY.";

            return null;
        }

        if ($date->greaterThanOrEqualTo($today)) {
            // Hari berjalan punya jalurnya sendiri, dan piring hari ini masih
            // harus lewat belt supaya conveyor dan finalisasi tetap benar.
            $errors[] = 'Hanya tanggal sebelum hari ini yang bisa diimpor.';

            return null;
        }

        if ($date->lessThan($oldest)) {
            $errors[] = 'Tanggal lebih dari ' . self::MAX_AGE_DAYS . ' hari yang lalu.';

            return null;
        }

        return $date;
    }

    /**
     * Format tanggal dibatasi ke daftar tertutup, bukan `Carbon::parse()`.
     * Parser bebas menerima "12/07/2026" sebagai Juli maupun Desember tergantung
     * lokal — kesalahan yang tidak pernah memunculkan error, hanya angka yang
     * nyasar setengah tahun.
     */
    private function toDate(string $value): ?Carbon
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                // `|` mereset jam ke 00:00 supaya sisa waktu "sekarang" tidak
                // ikut menempel. Carbon melempar untuk format yang tidak cocok,
                // jadi kegagalan ditangkap, bukan dibaca dari nilai balik.
                $date = Carbon::createFromFormat($format . '|', $value);
            } catch (\Throwable) {
                continue;
            }

            // Carbon menerima 2026-02-31 lalu menggesernya ke Maret. Format
            // ulang dan bandingkan supaya tanggal yang tidak ada tetap ditolak.
            if ($date && $date->format($format) === $value) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    private function parseTime(?string $value, array &$errors): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return self::DEFAULT_TIME;
        }

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
            $errors[] = "Jam '{$value}' tidak valid. Pakai HH:MM.";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, Menu>  $menus
     */
    private function resolveMenu(string $code, array $menus, array &$errors): ?Menu
    {
        if ($code === '') {
            $errors[] = 'Kolom menu_code kosong.';

            return null;
        }

        $menu = $menus[mb_strtolower($code)] ?? null;

        if (! $menu) {
            $errors[] = "Menu dengan kode '{$code}' tidak ada di brand outlet ini.";

            return null;
        }

        if (! $menu->plate_color_id) {
            // Warna piring adalah unit harganya. Piring tanpa warna tidak bisa
            // direkonsiliasi dengan POS sama sekali.
            $errors[] = "Menu '{$menu->menuname}' belum punya plate color.";

            return null;
        }

        return $menu;
    }

    private function parseQuantity($value, array &$errors): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            $errors[] = 'Kolom quantity kosong.';

            return 0;
        }

        if (! preg_match('/^\d+$/', $value)) {
            $errors[] = "Quantity '{$value}' bukan bilangan bulat.";

            return 0;
        }

        $quantity = (int) $value;

        if ($quantity < 1) {
            $errors[] = 'Quantity minimal 1.';

            return 0;
        }

        if ($quantity > self::MAX_QUANTITY_PER_ROW) {
            $errors[] = 'Quantity per baris maksimal ' . self::MAX_QUANTITY_PER_ROW . '.';

            return 0;
        }

        return $quantity;
    }

    private function parseStatus(?string $value, array &$errors): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            $errors[] = 'Kolom final_status kosong (isi sold atau waste).';

            return null;
        }

        $status = self::STATUS_ALIASES[$value] ?? null;

        if (! $status) {
            $errors[] = "Status '{$value}' tidak dikenal. Isi sold atau waste.";

            return null;
        }

        return $status;
    }

    private function cleanNotes(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * Menu brand outlet ini, dikunci kode huruf kecil.
     *
     * `menus.code` unik per brand, jadi peta ini tidak bisa dibuat global —
     * kode yang sama di brand lain akan menimpa dan piring tercatat ke menu
     * (dan harga) yang salah, tanpa error.
     *
     * @return array<string, Menu>
     */
    private function menusByCode(?string $brandId): array
    {
        $query = Menu::with('plateColor')->whereNotNull('code');

        $menus = $this->scopeToBrand($query, $brandId)->get();

        $map = [];

        foreach ($menus as $menu) {
            $map[mb_strtolower((string) $menu->code)] = $menu;
        }

        return $map;
    }

    /*
    |--------------------------------------------------------------------------
    | PEMBACAAN BERKAS
    |--------------------------------------------------------------------------
    */

    /**
     * Baca berkas jadi entri per baris, tanpa peduli formatnya.
     *
     * Dua format, satu aturan: CSV dan XLSX sama-sama diubah jadi matriks
     * string lebih dulu, lalu `entriesFrom()` yang memegang header, batas, dan
     * baris kosong. Kalau tiap format membawa aturannya sendiri, keduanya akan
     * menyimpang — dan bedanya cuma terlihat sebagai "baris tidak valid" pada
     * berkas yang isinya identik.
     *
     * @return array<int, array<string, string|int|null>>
     */
    private function parseFile(UploadedFile $file): array
    {
        ['header' => $header, 'rows' => $rows] = $this->isSpreadsheet($file)
            ? $this->readSpreadsheet($file)
            : $this->readCsv($file);

        return $this->entriesFrom($header, $rows);
    }

    /**
     * Isi berkas yang menentukan, bukan ekstensinya: berkas xlsx selalu diawali
     * tanda tangan zip. Operator yang menyimpan CSV dengan nama .xlsx (atau
     * sebaliknya) tetap terbaca benar — dan yang salah benar-benar salah.
     */
    private function isSpreadsheet(UploadedFile $file): bool
    {
        $path = $file->getRealPath();

        if (! $path) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 4) === "PK\x03\x04";
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $header
     * @param  array<int, array{line: int, values: array<int, string>}>  $rows
     * @return array<int, array<string, string|int|null>>
     */
    private function entriesFrom(array $header, array $rows): array
    {
        $missing = array_diff(self::REQUIRED_HEADERS, $header);

        if ($missing !== []) {
            throw new BusinessRuleException(
                'Kolom wajib tidak ada di header: ' . implode(', ', $missing) . '. '
                . 'Header yang terbaca: ' . (implode(', ', array_filter($header)) ?: '(kosong)') . '.'
            );
        }

        $entries = [];

        foreach ($rows as $row) {
            if ($this->isBlankRow($row['values'])) {
                continue;
            }

            $entry = ['line' => $row['line']];

            foreach ($header as $index => $column) {
                if ($column === null) {
                    continue;
                }

                $entry[$column] = isset($row['values'][$index])
                    ? trim((string) $row['values'][$index])
                    : null;
            }

            // Template mengirim berkas dengan `menu_code` sudah terisi untuk
            // semua menu aktif, jadi baris yang tidak dipakai tetap membawa
            // kode. Yang menentukan baris itu dipakai atau tidak adalah tiga
            // kolom isian — tanpa aturan ini, setiap menu yang tidak diproduksi
            // hari itu muncul sebagai baris error dan impornya batal.
            if ($this->isUnfilledRow($entry)) {
                continue;
            }

            if (count($entries) >= self::MAX_ROWS) {
                throw new BusinessRuleException(
                    'Berkas melebihi ' . self::MAX_ROWS . ' baris data. Pecah per periode.'
                );
            }

            $entries[] = $entry;
        }

        $plates = array_sum(array_map(
            fn ($row) => (int) preg_replace('/\D/', '', (string) ($row['quantity'] ?? 0)),
            $entries
        ));

        if ($plates > self::MAX_PLATES) {
            throw new BusinessRuleException(
                "Berkas ini akan membuat {$plates} baris piring, di atas batas "
                . self::MAX_PLATES . '. Pecah per periode.'
            );
        }

        return $entries;
    }

    /**
     * Baris yang belum diisi sama sekali: tidak ada tanggal, jumlah, maupun
     * status. `menu_code` dan `notes` sengaja tidak ikut dinilai — keduanya bisa
     * datang dari template tanpa berarti barisnya dipakai.
     *
     * @param  array<string, string|int|null>  $entry
     */
    private function isUnfilledRow(array $entry): bool
    {
        foreach (['date', 'quantity', 'final_status'] as $column) {
            if (trim((string) ($entry[$column] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | CSV
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{header: array<int, string|null>, rows: array<int, array{line: int, values: array<int, string>}>}
     */
    private function readCsv(UploadedFile $file): array
    {
        $path   = $file->getRealPath();
        $handle = $path ? @fopen($path, 'r') : false;

        if ($handle === false) {
            throw new BusinessRuleException('Berkas CSV tidak bisa dibaca.');
        }

        try {
            $headerLine = fgets($handle);

            if ($headerLine === false) {
                throw new BusinessRuleException('Berkas CSV kosong.');
            }

            $delimiter = $this->detectDelimiter($this->stripBom($headerLine));
            $header    = $this->normalizeHeader(
                str_getcsv(rtrim($this->stripBom($headerLine), "\r\n"), $delimiter)
            );

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $line++;

                // fgetcsv mengembalikan [null] untuk baris kosong. Baris kosong
                // di akhir berkas itu normal dari Excel, bukan kesalahan data.
                if ($values === [null]) {
                    continue;
                }

                $rows[] = [
                    'line'   => $line,
                    'values' => array_map(fn ($value) => (string) $value, $values),
                ];
            }

            return ['header' => $header, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Excel di Windows berbahasa Indonesia menyimpan CSV dengan `;`. Menebaknya
     * dari baris header lebih murah daripada menyuruh operator mengubah setelan
     * regional — dan tanpa ini seluruh baris terbaca sebagai satu kolom.
     */
    private function detectDelimiter(string $headerLine): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0];

        foreach (array_keys($candidates) as $delimiter) {
            $candidates[$delimiter] = substr_count($headerLine, $delimiter);
        }

        arsort($candidates);

        $best = array_key_first($candidates);

        return $candidates[$best] > 0 ? $best : ',';
    }

    /*
    |--------------------------------------------------------------------------
    | XLSX
    |--------------------------------------------------------------------------
    */

    /**
     * Berkas .xlsx dari template. Sheet-nya bisa lebih dari satu — template
     * sendiri membawa panduan dan daftar menu — jadi yang dipakai adalah sheet
     * pertama yang header-nya memuat semua kolom wajib, bukan sheet pertama
     * begitu saja. Operator yang mengunggah sambil membuka sheet panduan tetap
     * mengimpor data yang benar.
     *
     * `setReadDataOnly()` sengaja tidak dinyalakan: tanpa format angka, sel
     * tanggal Excel kembali sebagai bilangan (45678) dan tiap barisnya jadi
     * "tanggal tidak dikenali".
     *
     * @return array{header: array<int, string|null>, rows: array<int, array{line: int, values: array<int, string>}>}
     */
    private function readSpreadsheet(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! $path) {
            throw new BusinessRuleException('Berkas Excel tidak bisa dibaca.');
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadEmptyCells(false);

            $spreadsheet = $reader->load($path);
        } catch (\Throwable $exception) {
            throw new BusinessRuleException(
                'Berkas Excel tidak bisa dibaca. Simpan ulang sebagai .xlsx atau .csv lalu coba lagi.'
            );
        }

        try {
            $sheet  = $this->sheetWithRequiredHeaders($spreadsheet);
            $header = $this->normalizeHeader($this->rowValues($sheet, 1));

            $rows       = [];
            $highestRow = $sheet->getHighestDataRow();

            for ($line = 2; $line <= $highestRow; $line++) {
                $rows[] = ['line' => $line, 'values' => $this->rowValues($sheet, $line)];
            }

            return ['header' => $header, 'rows' => $rows];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function sheetWithRequiredHeaders(Spreadsheet $spreadsheet): Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $header = $this->normalizeHeader($this->rowValues($sheet, 1));

            if (array_diff(self::REQUIRED_HEADERS, $header) === []) {
                return $sheet;
            }
        }

        // Tidak ada yang cocok: kembalikan sheet pertama supaya pesan errornya
        // datang dari pemeriksaan header yang sama dengan jalur CSV — satu
        // kalimat, bukan dua yang berbeda untuk kesalahan yang sama.
        return $spreadsheet->getSheet(0);
    }

    /**
     * @return array<int, string>
     */
    private function rowValues(Worksheet $sheet, int $row): array
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $values = [];

        for ($column = 1; $column <= $lastColumn; $column++) {
            $values[] = $this->cellValue($sheet->getCell([$column, $row]));
        }

        return $values;
    }

    /**
     * Isi sel sebagai string, dengan dua perkara yang hanya ada di Excel:
     * tanggal/jam disimpan sebagai bilangan pecahan, dan angka bulat kembali
     * sebagai float. Keduanya harus dinormalkan di sini — validator di atas
     * hanya mengenal teks.
     */
    private function cellValue(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            try {
                $value = $cell->getCalculatedValue();
            } catch (\Throwable) {
                $value = null;
            }
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            $moment = ExcelDate::excelToDateTimeObject((float) $value);

            // Serial di bawah 1 tidak punya bagian tanggal sama sekali — itu
            // sel jam, dan mengembalikannya sebagai tanggal 1899 akan menolak
            // kolom `time` yang isinya benar.
            return ((float) $value) < 1
                ? $moment->format('H:i')
                : $moment->format('Y-m-d');
        }

        if (is_float($value)) {
            // (string) 12.0 menghasilkan "12", yang memang yang dibutuhkan
            // kolom quantity. Pecahan tetap terbawa apa adanya supaya 12.5
            // ditolak validator, bukan dibulatkan diam-diam.
            return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        }

        return trim((string) $value);
    }

    /*
    |--------------------------------------------------------------------------
    | HEADER
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, string|null>  $header
     * @return array<int, string|null>
     */
    private function normalizeHeader(array $header): array
    {
        return array_map(function ($column) {
            $key = mb_strtolower(trim((string) $column));
            $key = preg_replace('/[\s\-]+/', '_', $key);

            if ($key === '') {
                return null;
            }

            return self::HEADER_ALIASES[$key] ?? $key;
        }, $header);
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
