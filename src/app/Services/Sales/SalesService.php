<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessRuleException;
use App\Models\ClosingReport;
use App\Models\ProductionStatusCorrection;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use App\Http\Resources\Sales\SalesClosingReportResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use App\Services\BaseAggregateService;
use App\Services\Production\ProductionItemService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SalesService extends BaseAggregateService
{
    protected string $model = SalesHeader::class;

    protected string $itemModel = SalesItem::class;
    protected string $itemForeignKey = 'sales_id';

    public function __construct(
        private readonly ProductionItemService $productionItems,
    ) {
    }

    // ==========================
    // UPSERT PER (OUTLET, DATE)
    // ==========================
    /**
     * There is exactly one sales header per outlet per day — the Sales Input
     * screen only ever POSTs, so a second save for the same day has to update
     * the existing header instead of inserting a rival one. Enforced in the
     * database by a partial unique index on (outlet_id, date).
     */
    public function create(array $data): Model
    {
        $date = Carbon::parse($data['date'])->toDateString();

        $this->assertClosingReportNotSubmitted($data['outlet_id'], $date);

        if ($this->wasCorrected($data['outlet_id'], $date)) {
            $this->assertSnapshotMatchesProduction($data['outlet_id'], $date, $data['items'] ?? []);
        }

        $existing = SalesHeader::query()
            ->where('outlet_id', $data['outlet_id'])
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            return $this->update($existing->id, $data);
        }

        return parent::create($data);
    }

    /**
     * Koreksi Status Produksi hanya boleh sebelum Sales Input tanggal itu ada.
     * Tapi layar Sales Input bisa sudah menarik angka (Get Data POS) sebelum
     * koreksi, lalu menyimpan sesudahnya — snapshot lama masuk diam-diam.
     */
    private function wasCorrected(string $outletId, string $date): bool
    {
        return ProductionStatusCorrection::query()
            ->where('outlet_id', $outletId)
            ->whereDate('production_date', $date)
            ->exists();
    }

    /**
     * Untuk tanggal yang pernah dikoreksi, angka produksi yang disimpan harus
     * angka terbaru. Dasar perbandingannya sama dengan sumber angka di layar:
     * per warna dari `totalsByPlateColor()` (Get Data POS), per menu dari
     * rincian produksi.
     */
    private function assertSnapshotMatchesProduction(string $outletId, string $date, array $items): void
    {
        $sold  = $this->productionItems->totalsByPlateColor($outletId, $date, 'sold_at');
        $waste = $this->productionItems->totalsByPlateColor($outletId, $date, 'wasted_at');
        $menus = $this->productionItems->totalsByMenu($outletId, $date);

        foreach ($items as $item) {
            $color = $item['plate_color_id'] ?? null;

            $colorMatches = (int) ($item['production_sold'] ?? 0) === (int) ($sold[$color] ?? 0)
                && (int) ($item['production_waste'] ?? 0) === (int) ($waste[$color] ?? 0);

            $detailsMatch = collect($item['details'] ?? [])->every(function ($detail) use ($menus, $color) {
                $current = $menus[($detail['menu_id'] ?? '') . '|' . $color] ?? ['sold' => 0, 'waste' => 0];

                return (int) ($detail['total_sold'] ?? 0) === $current['sold']
                    && (int) ($detail['total_wasted'] ?? 0) === $current['waste'];
            });

            if (! $colorMatches || ! $detailsMatch) {
                throw new BusinessRuleException(
                    'Data produksi tanggal ini sudah dikoreksi admin, jadi angka di layar sudah tidak berlaku. '
                    . 'Klik Get Data POS ulang lalu simpan lagi.'
                );
            }
        }
    }

    /**
     * A submitted closing report is signed off by the kitchen and operation
     * leaders, and its entries are derived from sales_items. Letting a later
     * save rewrite those numbers would leave a signed report disagreeing with
     * its own source, silently.
     */
    private function assertClosingReportNotSubmitted(string $outletId, string $date): void
    {
        $submitted = ClosingReport::query()
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->where('status', 'submitted')
            ->exists();

        if ($submitted) {
            throw new BusinessRuleException(
                'Closing report tanggal ini sudah disubmit, jadi data sales-nya tidak bisa diubah lagi. '
                . 'Hubungi admin kalau memang perlu koreksi.'
            );
        }
    }

    /**
     * Membatalkan draft Sales Input (soft delete), oleh admin.
     *
     * Ada karena Koreksi Status Produksi terkunci begitu Sales Input tanggal
     * itu ada, draft sekalipun. Yang hilang hanya pekerjaan yang memang harus
     * diulang: setelah koreksi operator wajib Get Data POS ulang, dan Get Data
     * POS selalu mengosongkan adjustment/compensation.
     *
     * Hanya draft. Sales `submitted` adalah dasar closing report yang
     * ditandatangani dan dikirim ke BI — membatalkannya butuh alur lain.
     *
     * Closing draft yang terlanjur menunjuk header ini tidak dihapus —
     * `closing_reports` unique (outlet_id, date) tanpa pengecualian soft
     * delete, jadi baris yang dihapus akan menghalangi closing berikutnya.
     * Tautannya dilepas dan entries-nya dibuang supaya dibangkitkan ulang dari
     * Sales Input yang baru (`findOrCreateReport()` menautkan ulang).
     */
    public function cancelDraft(string $id, string $reason): SalesHeader
    {
        return DB::transaction(function () use ($id, $reason) {
            $header = SalesHeader::query()->lockForUpdate()->findOrFail($id);

            if ($header->status !== 'draft') {
                throw new BusinessRuleException(
                    'Hanya Sales Input berstatus draft yang bisa dibatalkan. '
                    . 'Sales Input ini sudah disubmit.',
                    409
                );
            }

            $closing = ClosingReport::query()->where('sales_id', $header->id)->first();

            if ($closing) {
                $closing->entries()->withTrashed()->forceDelete();
                $closing->update(['sales_id' => null]);
            }

            $header->update(['deletion_reason' => $reason]);
            $header->delete();

            return $header;
        });
    }

    /**
     * Draft yang dibatalkan untuk outlet + tanggal itu, terbaru dulu.
     *
     * @return \Illuminate\Support\Collection<int, SalesHeader>
     */
    public function cancelledDrafts(string $outletId, string $date)
    {
        return SalesHeader::onlyTrashed()
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->whereNotNull('deletion_reason')
            ->orderByDesc('deleted_at')
            ->get();
    }

    // ==========================
    // OVERRIDE CREATE ITEMS
    // ==========================
    protected function createItems(Model $model, array $items): void
    {
        foreach ($items as $item) {

            $details = $item['details'] ?? [];
            unset($item['details']);

            $adjustment = $item['adjustment'] ?? 0;
            $compensation = $item['compensation'] ?? 0;

            // 🔥 HITUNG ULANG SELISIH
            $item['selisih'] = $item['pos_sold'] - (
                $item['production_sold'] + $adjustment + $compensation
            );

            $item[$this->itemForeignKey] = $model->id;

            $this->applyFingerprint($item);

            $salesItem = SalesItem::create($item);

            // 🔹 SAVE DETAILS
            $this->createDetails($salesItem, $details);
        }
    }

    // ==========================
    // OVERRIDE SYNC ITEMS
    // ==========================
    protected function syncItems(Model $model, array $items): void
    {
        $existingIds = SalesItem::where('sales_id', $model->id)
            ->pluck('id')
            ->toArray();

        $incomingIds = [];

        foreach ($items as $item) {

            $details = $item['details'] ?? [];
            unset($item['details']);

            $adjustment = $item['adjustment'] ?? 0;
            $compensation = $item['compensation'] ?? 0;

            // 🔥 HITUNG ULANG
            $item['selisih'] = $item['pos_sold'] - (
                $item['production_sold'] + $adjustment + $compensation
            );

            // UPDATE
            if (!empty($item['id'])) {

                $incomingIds[] = $item['id'];

                $record = SalesItem::find($item['id']);
                if ($record) {
                    unset($item['id']);
                    $this->applyFingerprint($item, false);

                    $record->update($item);

                    // 🔹 SYNC DETAILS
                    $this->syncDetails($record, $details);
                }
            }
            // CREATE
            else {
                $item['sales_id'] = $model->id;

                $this->applyFingerprint($item);

                $newItem = SalesItem::create($item);

                $this->createDetails($newItem, $details);
            }
        }

        // DELETE ITEMS
        $deleteIds = array_diff($existingIds, $incomingIds);

        if (!empty($deleteIds)) {
            SalesItem::whereIn('id', $deleteIds)->delete();

            // 🔥 HAPUS DETAIL JUGA
            SalesItemDetail::whereIn('sales_item_id', $deleteIds)->delete();
        }
    }

    // ==========================
    // DETAIL HANDLER
    // ==========================
    protected function createDetails(SalesItem $item, array $details): void
    {
        foreach ($details as $detail) {

            $detail['sales_item_id'] = $item->id;

            $this->applyFingerprintDetail($detail);

            SalesItemDetail::create($detail);
        }
    }

    protected function syncDetails(SalesItem $item, array $details): void
    {
        $existingIds = SalesItemDetail::where('sales_item_id', $item->id)
            ->pluck('id')
            ->toArray();

        $incomingIds = [];

        foreach ($details as $detail) {

            // UPDATE
            if (!empty($detail['id'])) {

                $incomingIds[] = $detail['id'];

                $record = SalesItemDetail::find($detail['id']);
                if ($record) {
                    unset($detail['id']);
                    $this->applyFingerprintDetail($detail, false);
                    $record->update($detail);
                }
            }
            // CREATE
            else {
                $detail['sales_item_id'] = $item->id;
                $this->applyFingerprintDetail($detail);

                SalesItemDetail::create($detail);
            }
        }

        // DELETE
        $deleteIds = array_diff($existingIds, $incomingIds);

        if (!empty($deleteIds)) {
            SalesItemDetail::whereIn('id', $deleteIds)->delete();
        }
    }

    // ==========================
    // DETAIL FINGERPRINT
    // ==========================
    protected function applyFingerprintDetail(array &$item, bool $isCreate = true): void
    {
        if ($isCreate) {
            $item['created_by'] = auth()->id();
        }

        $item['updated_by'] = auth()->id();
    }

    // ==========================
    // QUERY
    // ==========================
    public function getByDateOutlet(string $outletId, string $date)
    {
        return SalesHeader::with(['outlet', 'items.details.menu.plateColor', 'items.plateColor'])
            ->where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->first();
    }

    public function show(string $id)
    {
        return SalesHeader::with(['items.details', 'items.plateColor'])
            ->findOrFail($id);
    }

   
    public function list(Request $request): LengthAwarePaginator
    {
        $query = $this->query();

        // ==========================
        // RELATIONS (custom)
        // ==========================
        $query->with([
            'items.details',
            'items.plateColor'
        ]);

        // ==========================
        // FILTER KHUSUS
        // ==========================
        // Urutan kunci sama dengan OutletAccess: outlet yang disaring harus
        // outlet yang sudah diperiksa izinnya. Wajib — divalidasi controller.
        $query->where('outlet_id', $request->query('outletId') ?? $request->query('outlet_id'));

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // ==========================
        // SEARCH (optional)
        // ==========================
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->orWhere('notes', 'like', "%{$search}%");
                // tambahin field lain kalau perlu
            });
        }

        // ==========================
        // SORT (default terbaru)
        // ==========================
        $query->orderBy('date', 'desc');

        // ==========================
        // PAGINATION
        // ==========================
        $perPage = min((int) $request->query('per_page', 15), 100);

        return $query->paginate($perPage);
    }

    public function getClosingReportData(string $outletId, string $date): array
    {
        $salesHeader = SalesHeader::query()
                ->with([
                    'outlet',
                    'items.platecolor', 
                    'items.details.menu.plateColor',
                ])
                ->where('outlet_id', $outletId)
                ->whereDate('date', $date)  
                ->where('status', 'submitted') 
                ->first();

            if (!$salesHeader) {

                return [
                    'status' => false,
                    'message' => 'No submitted sales data found',
                    'data' => null,
                ];
            }

            return [
                'status' => true,
                'message' => 'Success',
                'data' => (
                    new SalesClosingReportResource($salesHeader)
                )->resolve(),
            ];
    }
}
