<?php

namespace App\Services\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ProductionItem;
use App\Models\WasteCategory;
use App\Models\WasteRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Payload `closingreport.submitted` untuk BI. Bentuknya kontrak dengan
 * pihak luar — mengubah field berarti menaikkan `version`.
 *
 * - v1: angka per menu.
 * - v2: v1 + `items[].wasteByCategory` (docs/jawaban-bi-closing-report-v2.md).
 * - v3: v2 + harga satuan piring dan nilai rupiah per sold/waste/adjustment/
 *   compensation (`unitPrice`, `soldAmount`, `wasteAmount`, `adjustmentAmount`,
 *   `compensationAmount`), plus nama warna piringnya (`plateColor`).
 *
 * Versi yang disusun dipilih lewat config `rabbitmq.closing_report.payload_version`,
 * bukan otomatis: consumer BI harus sudah menerima versi itu sebelum kita mengirimnya.
 *
 * `sentAt` sengaja tidak diisi di sini: payload disimpan di outbox saat submit,
 * sedangkan waktu kirim baru diketahui saat publish (bisa jauh setelahnya kalau
 * broker sempat mati). Lihat ClosingReportOutboxService::publish().
 */
class ClosingReportPayloadBuilder
{
    public const EVENT = 'closingreport.submitted';
    public const VERSION_1 = 1;
    public const VERSION_2 = 2;
    public const VERSION_3 = 3;

    public function __construct(private readonly ClosingReportMenuLines $menuLines)
    {
    }

    public function build(ClosingReport $report): array
    {
        $report->loadMissing('outlet.brandMaster');

        $outlet = $report->outlet;
        $brand = $outlet->brandMaster;
        $version = $this->version();

        $lines = $this->menuLines->for($report->sales_id, $report->outlet_id, $report->date->toDateString());

        $items = $this->items($lines);

        if ($version >= self::VERSION_2) {
            $items = $this->withWasteByCategory($report, $items);
        }

        if ($version >= self::VERSION_3) {
            $items = $this->withPrices($items, $lines);
        }

        return [
            'event'     => self::EVENT,
            'version'   => $version,
            'messageId' => $report->id,
            'data'      => [
                'closingReportId' => $report->id,
                'date'            => $report->date->toDateString(),
                'outlet'          => ['code' => $outlet->code, 'name' => $outlet->name],
                'brand'           => $brand ? ['code' => $brand->code, 'name' => $brand->name] : null,
                'items'           => $items,
            ],
        ];
    }

    /**
     * Versi yang akan disusun build(). Nilai dari .env selalu string. Selain
     * 2 dan 3, semuanya v1 — satu-satunya versi yang pasti diterima consumer BI.
     */
    public function version(): int
    {
        $configured = (int) config('rabbitmq.closing_report.payload_version', self::VERSION_1);

        return in_array($configured, [self::VERSION_2, self::VERSION_3], true)
            ? $configured
            : self::VERSION_1;
    }

    /**
     * v3: harga satuan piring + nilai rupiah tiap angka item.
     *
     * Harga satuan dari ClosingReportMenuLines — angka yang sama dengan
     * `sellingPrice` di layar Closing Report. Master tidak menyimpan riwayat
     * harga, jadi yang dipakai harga saat pesan disusun: saat submit (payload
     * dibekukan di outbox), atau saat `--rebuild`.
     */
    private function withPrices(array $items, Collection $lines): array
    {
        $lineOfMenu = $lines->keyBy('menuId');

        return array_map(function (array $item) use ($lineOfMenu) {
            $line = $lineOfMenu->get($item['menuId']);
            $unitPrice = (float) ($line['unitPrice'] ?? 0.0);
            $amount = fn (int $quantity) => round($quantity * $unitPrice, 2);

            return $item + [
                'unitPrice'          => $unitPrice,
                'soldAmount'         => $amount($item['sold']),
                'wasteAmount'        => $amount($item['waste']),
                'adjustmentAmount'   => $amount($item['adjustment']),
                'compensationAmount' => $amount($item['compensation']),
                'plateColor'         => $this->plateColorName($line),
            ];
        }, $items);
    }

    /**
     * v3: nama warna piring yang menjadi dasar `unitPrice` — diminta BI untuk
     * mengisi warna menu di sisi mereka (docs/jawaban-bi-closing-report-v3.md
     * bagian 6). Isinya `platename`, nama yang sama dengan produk piring di POS
     * (POSService mencocokkannya dengan LOWER(TRIM())). Warna yang sama dengan
     * harga, jadi keduanya tidak pernah menunjuk baris yang berbeda.
     *
     * Tanpa warna → `null`; BI menerima dan tidak mengubah warna menunya.
     */
    private function plateColorName(?array $line): ?string
    {
        $name = trim((string) ($line['plateColor']?->platename ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Bentuk v1 satu baris per menu. Barisnya dari ClosingReportMenuLines —
     * sama persis dengan baris di layar Closing Report.
     *
     * Rincian per menu (`sales_item_details`) hanya ada kalau operator membuka
     * dialog rincian di Sales Input. Dulu payload dibangun dari tabel itu saja,
     * jadi sales yang disubmit tanpa membuka dialog terkirim dengan `items: []`
     * dan ditolak BI — karena itu barisnya juga dari production_items.
     */
    private function items(Collection $lines): array
    {
        return $lines
            ->map(fn (array $line) => [
                'menuId'         => $line['menuId'],
                'menuCode'       => $line['menuCode'],
                'menuName'       => $line['menuName'],
                'productionDate' => $line['productionDate'],
                'sold'           => $line['sold'],
                'waste'          => $line['waste'],
                'adjustment'     => $line['adjustment'],
                'compensation'   => $line['compensation'],
            ])
            ->all();
    }

    /**
     * v2: tempel `wasteByCategory` ke setiap item.
     *
     * Jaminan ke BI: total quantity = `waste` item. `waste` bisa datang dari
     * rincian Sales Input (dibekukan), sedangkan kategorinya dari piring di
     * production_items, jadi selisih keduanya disesuaikan di reconcile().
     */
    private function withWasteByCategory(ClosingReport $report, array $items): array
    {
        $byMenu = $this->categoryCountsByMenu($report);

        return array_map(function (array $item) use ($report, $byMenu) {
            $counts = $byMenu[$item['menuId']] ?? [];
            $counted = array_sum(array_column($counts, 'quantity'));

            if ($counted !== $item['waste']) {
                Log::warning('closingreport: rincian kategori waste disesuaikan ke angka waste', [
                    'closingReportId' => $report->id,
                    'menuId'          => $item['menuId'],
                    'waste'           => $item['waste'],
                    'categorized'     => $counted,
                ]);
            }

            $item['wasteByCategory'] = $this->sorted($this->reconcile($counts, $item['waste']));

            return $item;
        }, $items);
    }

    /**
     * Piring waste di outlet + hari laporan (filter sama dengan
     * productionByMenu()), dikelompokkan per menu lalu per kategori.
     *
     * Kategori = snapshot di waste_records, bukan mapping reason terkini.
     * Satu piring dihitung sekali: `recordFromItems()` tidak mencegah
     * duplikat, jadi yang dipakai record hidup yang tercatat pertama. Piring
     * tanpa record, atau record tanpa kategori, masuk UNCATEGORIZED.
     *
     * @return array<string, array<string, array{categoryCode: string, categoryName: string, quantity: int}>>
     */
    private function categoryCountsByMenu(ClosingReport $report): array
    {
        $plates = ProductionItem::query()
            ->where('outlet_id', $report->outlet_id)
            ->whereNotNull('menu_id')
            ->whereDate('produced_at', $report->date->toDateString())
            ->whereNotNull('wasted_at');

        $categoryOfPlate = WasteRecord::query()
            ->whereIn('production_item_id', (clone $plates)->select('id'))
            ->orderBy('recorded_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['production_item_id', 'waste_category_id'])
            ->unique('production_item_id')
            ->pluck('waste_category_id', 'production_item_id');

        $categories = WasteCategory::withTrashed()
            ->whereIn('id', $categoryOfPlate->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($plates->get(['id', 'menu_id', 'quantity']) as $plate) {
            $category = $categories->get($categoryOfPlate->get($plate->id));
            $code = $category?->code ?? WasteCategory::UNCATEGORIZED_CODE;

            $result[$plate->menu_id][$code] ??= [
                'categoryCode' => $code,
                'categoryName' => $category?->category_name ?? WasteCategory::UNCATEGORIZED_NAME,
                'quantity'     => 0,
            ];
            $result[$plate->menu_id][$code]['quantity'] += (int) ($plate->quantity ?? 1);
        }

        return $result;
    }

    /**
     * Samakan total dengan `waste`. Kurang → selisihnya ke UNCATEGORIZED.
     * Lebih → dipangkas dari UNCATEGORIZED dulu, lalu dari kategori terkecil
     * (kebalikan urutan kirim), supaya kategori yang pasti tersisa paling lama.
     *
     * @param  array<string, array{categoryCode: string, categoryName: string, quantity: int}>  $counts
     */
    private function reconcile(array $counts, int $waste): array
    {
        $diff = $waste - array_sum(array_column($counts, 'quantity'));
        $uncategorized = WasteCategory::UNCATEGORIZED_CODE;

        if ($diff > 0) {
            $counts[$uncategorized] ??= [
                'categoryCode' => $uncategorized,
                'categoryName' => WasteCategory::UNCATEGORIZED_NAME,
                'quantity'     => 0,
            ];
            $counts[$uncategorized]['quantity'] += $diff;

            return $counts;
        }

        $excess = -$diff;
        $trimOrder = array_reverse($this->sorted($counts));
        usort($trimOrder, fn ($a, $b) => ($b['categoryCode'] === $uncategorized) <=> ($a['categoryCode'] === $uncategorized));

        foreach ($trimOrder as $row) {
            if ($excess === 0) {
                break;
            }

            $take = min($excess, $row['quantity']);
            $counts[$row['categoryCode']]['quantity'] -= $take;
            $excess -= $take;
        }

        return array_filter($counts, fn ($row) => $row['quantity'] > 0);
    }

    /**
     * Quantity turun, lalu categoryCode naik — `--rebuild` harus menghasilkan
     * isi yang identik.
     */
    private function sorted(array $counts): array
    {
        $rows = array_values($counts);

        usort($rows, fn ($a, $b) => [$b['quantity'], $a['categoryCode']] <=> [$a['quantity'], $b['categoryCode']]);

        return $rows;
    }
}
