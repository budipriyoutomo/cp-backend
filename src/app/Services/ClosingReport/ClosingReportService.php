<?php

namespace App\Services\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ClosingReportEntry;
use App\Models\PlateColors;
use App\Models\POSData;
use App\Models\ProductionItem;
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
        'entries.plateColor',
    ];

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

    public function getData(string $outletId, string $date): ClosingReport
    {
        return DB::transaction(function () use ($outletId, $date) {
            $report = ClosingReport::firstOrCreate(
                [
                    'outlet_id' => $outletId,
                    'date' => Carbon::parse($date)->toDateString(),
                ],
                [
                    'status' => 'draft',
                ]
            );

            if ($report->status === 'draft') {
                $this->syncEntriesFromOperationalData($report);
            }

            return $this->show($report->id);
        });
    }

    public function saveDraft(array $data, ?string $id = null): ClosingReport
    {
        return DB::transaction(function () use ($data, $id) {
            $report = $id
                ? ClosingReport::findOrFail($id)
                : ClosingReport::firstOrNew([
                    'outlet_id' => $data['outletId'],
                    'date' => Carbon::parse($data['date'])->toDateString(),
                ]);

            if ($report->status === 'submitted') {
                throw new \Exception('Submitted closing report cannot be edited.');
            }

            $report->fill([
                'outlet_id' => $data['outletId'] ?? $report->outlet_id,
                'date' => isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : $report->date,
                'status' => 'draft',
                'kitchen_leader' => $data['kitchenLeader'] ?? $report->kitchen_leader,
                'operation_leader' => $data['operationLeader'] ?? $report->operation_leader,
                'notes' => $data['notes'] ?? $report->notes,
            ]);
            $report->save();

            $this->syncEntriesFromPayload($report, $data['entries'] ?? []);

            return $this->show($report->id);
        });
    }

    public function submit(array $data): ClosingReport
    {
        return DB::transaction(function () use ($data) {
            $report = ClosingReport::firstOrCreate(
                [
                    'outlet_id' => $data['outletId'],
                    'date' => Carbon::parse($data['date'])->toDateString(),
                ],
                [
                    'status' => 'draft',
                ]
            );

            if ($report->entries()->count() === 0) {
                $this->syncEntriesFromOperationalData($report);
            }

            $report->update([
                'status' => 'submitted',
                'kitchen_leader' => $data['kitchenLeader'],
                'operation_leader' => $data['operationLeader'],
                'waste_photo_urls' => $data['wastePhotoUrls'] ?? [],
                'notes' => $data['notes'] ?? $report->notes,
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ]);

            return $this->show($report->id);
        });
    }

    public function delete($id): ?ClosingReport
    {
        $report = ClosingReport::findOrFail($id);

        if ($report->status !== 'draft') {
            throw new \Exception('Only draft closing reports can be deleted.');
        }

        $report->delete();

        return $report;
    }

    private function syncEntriesFromOperationalData(ClosingReport $report): void
    {
        $entries = $this->buildOperationalEntries($report->outlet_id, $report->date);

        $this->syncEntriesFromPayload($report, $entries);
    }

    private function syncEntriesFromPayload(ClosingReport $report, array $payloadEntries): void
    {
        $operationalEntries = collect(
            $this->buildOperationalEntries($report->outlet_id, $report->date)
        )->keyBy('plateColorId');

        foreach ($payloadEntries as $payloadEntry) {
            $plateColorId = $payloadEntry['plateColorId'];
            $base = $operationalEntries->get($plateColorId, [
                'plateColorId' => $plateColorId,
                'produced' => 0,
                'sold' => 0,
                'waste' => 0,
                'posSold' => 0,
            ]);

            $posSold = (int) ($payloadEntry['posSold'] ?? $base['posSold']);
            $adjustment = (int) ($payloadEntry['adjustment'] ?? 0);
            $compensation = (int) ($payloadEntry['compensation'] ?? 0);
            $sold = (int) $base['sold'];

            ClosingReportEntry::updateOrCreate(
                [
                    'closing_report_id' => $report->id,
                    'plate_color_id' => $plateColorId,
                ],
                [
                    'produced' => (int) $base['produced'],
                    'sold' => $sold,
                    'waste' => (int) $base['waste'],
                    'pos_sold' => $posSold,
                    'adjustment' => $adjustment,
                    'compensation' => $compensation,
                    'compensation_reason' => $payloadEntry['compensationReason'] ?? null,
                    'selisih' => $posSold - ($sold + $adjustment + $compensation),
                ]
            );
        }
    }

    private function buildOperationalEntries(string $outletId, $date): array
    {
        $date = Carbon::parse($date)->toDateString();

        $produced = ProductionItem::where('outlet_id', $outletId)
            ->whereDate('produced_at', $date)
            ->selectRaw('plate_color, SUM(quantity) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');

        $sold = ProductionItem::where('outlet_id', $outletId)
            ->whereDate('sold_at', $date)
            ->selectRaw('plate_color, SUM(quantity) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');

        $waste = ProductionItem::where('outlet_id', $outletId)
            ->whereDate('wasted_at', $date)
            ->selectRaw('plate_color, SUM(quantity) as total')
            ->groupBy('plate_color')
            ->pluck('total', 'plate_color');

        $posSold = POSData::where('outlet_id', $outletId)
            ->whereDate('date', $date)
            ->selectRaw('plate_color_id, SUM(sold) as total')
            ->groupBy('plate_color_id')
            ->pluck('total', 'plate_color_id');

        return PlateColors::where('is_active', true)
            ->orderBy('price')
            ->get()
            ->map(function (PlateColors $plateColor) use ($produced, $sold, $waste, $posSold) {
                $plateColorId = $plateColor->id;
                $pos = (int) ($posSold[$plateColorId] ?? 0);
                $productionSold = (int) ($sold[$plateColorId] ?? 0);

                return [
                    'plateColorId' => $plateColorId,
                    'produced' => (int) ($produced[$plateColorId] ?? 0),
                    'sold' => $productionSold,
                    'waste' => (int) ($waste[$plateColorId] ?? 0),
                    'posSold' => $pos,
                    'adjustment' => 0,
                    'compensation' => 0,
                    'selisih' => $pos - $productionSold,
                ];
            })
            ->all();
    }
}
