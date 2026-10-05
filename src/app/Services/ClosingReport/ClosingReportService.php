<?php

namespace App\Services\ClosingReport;

use App\Exceptions\BusinessRuleException;
use App\Models\ClosingReport;
use App\Models\ClosingReportEntry;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ClosingReportService extends BaseService
{
    protected string $model = ClosingReport::class;

    protected array $relations = [
        'outlet',
        'sales.outlet',
        'sales.items.plateColor',
        'sales.items.details.menu.plateColor',
        'entries.plateColor',
        'outbox',
    ];

    public function __construct(
        private readonly ClosingReportOutboxService $outbox,
    ) {
    }

    public function list(Request $request): LengthAwarePaginator
    {
        $query = $this->query()->with($this->relations);

        if ($request->filled('outletId')) {
            $query->where('outlet_id', $request->outletId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('startDate')) {
            $query->whereDate('date', '>=', $request->startDate);
        }

        if ($request->filled('endDate')) {
            $query->whereDate('date', '<=', $request->endDate);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return $query->orderByDesc('date')->paginate($perPage);
    }

    public function show(string $id): ClosingReport
    {
        return ClosingReport::with($this->relations)->findOrFail($id);
    }

    public function getData(
        string $outletId,
        string $date
    ): ClosingReport {

        return DB::transaction(function () use (
            $outletId,
            $date
        ) {
            $salesHeader = SalesHeader::where(
                                'outlet_id',
                                $outletId
                            )
                            ->whereDate(
                                'date',
                                Carbon::parse($date)->toDateString()
                            )
                            ->firstOrFail();

            if ($salesHeader->status !== 'submitted') {
                throw new BusinessRuleException(
                    'Sales Input harus disubmit terlebih dahulu.'
                );
            }
            

            $report = $this->findOrCreateReport($outletId, $date, $salesHeader);

            if ($report->status === 'submitted') {
                return $this->show($report->id);
            }

            if (! $report->entries()->exists()) {
                $this->generateEntries($report);
            }

            return $this->show($report->id);
        });
    }
 

    /**
     * Pesan ke BI diantre di dalam transaksi tapi baru dikirim setelah commit:
     * submit yang di-rollback tidak boleh sempat mengirim apa pun. Broker yang
     * mati tidak menggagalkan submit — publish() mencatatnya untuk dicoba ulang.
     */
    public function submit(array $data): ClosingReport
    {
        $pending = DB::transaction(function () use ($data) {

            $salesHeader = SalesHeader::with('items')
                ->where('outlet_id', $data['outletId'])
                ->whereDate(
                    'date',
                    Carbon::parse($data['date'])->toDateString()
                )
                ->firstOrFail();

            if ($salesHeader->status !== 'submitted') {
                throw new BusinessRuleException(
                    'Sales Input harus disubmit terlebih dahulu.'
                );
            }

            $report = $this->findOrCreateReport($data['outletId'], $data['date'], $salesHeader);

            if ($report->status === 'submitted') {
                throw new BusinessRuleException(
                    'Closing report already submitted.',
                    409
                );
            }

            if (! $report->entries()->exists()) {
                $this->generateEntries($report);
            }

            $report->update([
                'status' => 'submitted',
                'kitchen_leader' => $data['kitchenLeader'],
                'operation_leader' => $data['operationLeader'],
                'waste_photo_urls' => $data['wastePhotoUrls'] ?? [],
                'notes' => $data['notes'] ?? null,
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ]);

            return $this->outbox->enqueue($report);
        });

        $this->outbox->publish($pending);

        // Dimuat ulang setelah publish supaya publishStatus di respons adalah
        // hasil kiriman ini, bukan `pending` dari dalam transaksi.
        return $this->show($pending->closing_report_id);
    }


    public function delete($id): ?ClosingReport
    {
        $report = ClosingReport::findOrFail($id);

        if ($report->status !== 'draft') {
            throw new BusinessRuleException('Only draft closing reports can be deleted.', 409);
        }

        $report->delete();

        return $report;
    }
  
    /**
     * Cari report outlet/hari itu, atau buat draft-nya.
     *
     * Dicari dengan whereDate, bukan firstOrCreate(['date' => 'Y-m-d']): cast
     * `date` menyimpan 'Y-m-d 00:00:00', yang di SQLite tidak sama dengan
     * 'Y-m-d' — pencarian meleset, lalu INSERT menabrak unique (outlet_id, date)
     * dan submit ulang dijawab 500, bukan 409. PostgreSQL kebetulan lolos
     * karena kolomnya bertipe date.
     */
    private function findOrCreateReport(string $outletId, string $date, SalesHeader $salesHeader): ClosingReport
    {
        $day = Carbon::parse($date)->toDateString();

        $report = ClosingReport::where('outlet_id', $outletId)
            ->whereDate('date', $day)
            ->first();

        if (! $report) {
            return ClosingReport::create([
                'outlet_id' => $outletId,
                'date'      => $day,
                'sales_id'  => $salesHeader->id,
                'status'    => 'draft',
            ]);
        }

        if (! $report->sales_id) {
            $report->update(['sales_id' => $salesHeader->id]);
        }

        return $report;
    }

    private function buildOperationalEntries(
        ClosingReport $report
    ): array {
        
        $salesHeader = SalesHeader::with('items')
            ->findOrFail($report->sales_id);

            if ($salesHeader->status !== 'submitted') {
                throw new BusinessRuleException(
                    'Sales Input harus disubmit terlebih dahulu.'
                );
            }

        return collect($salesHeader->items)
            ->map(function (SalesItem $item) {
                return [
                    'plateColorId' => $item->plate_color_id,
                    'produced' => $item->production_sold + $item->production_waste,
                    'sold' => $item->production_sold,
                    'waste' => $item->production_waste,
                    'posSold' => $item->pos_sold,
                    'adjustment' => $item->adjustment,
                    'compensation' => $item->compensation,
                    'selisih' => $item->selisih,
                ];
            })
            ->values()
            ->all();
    }

    private function generateEntries(
        ClosingReport $report
    ): void {

        $entries = $this->buildOperationalEntries($report);

        foreach ($entries as $entry) {

            ClosingReportEntry::updateOrCreate(
                [
                    'closing_report_id' => $report->id,
                    'plate_color_id' => $entry['plateColorId'],
                ],
                [
                    'produced' => $entry['produced'],
                    'sold' => $entry['sold'],
                    'waste' => $entry['waste'],
                    'pos_sold' => $entry['posSold'],
                    'adjustment' => $entry['adjustment'],
                    'compensation' => $entry['compensation'],
                    'selisih' => $entry['selisih'],
                ]
            );
        }
    }

}
