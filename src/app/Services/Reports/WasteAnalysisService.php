<?php

namespace App\Services\Reports;

use App\Models\PlateColors;
use App\Models\ProductionItem;
use App\Models\WasteRecord;
use App\Support\Uuid;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WasteAnalysisService
{
    /**
     * Aggregate waste analytics for an outlet over a date range.
     */
    public function get(string $outletId, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end   = Carbon::parse($endDate)->endOfDay();

        // ── Waste grouped by plate color.
        // NOTE: plate_colors.id is a uuid while waste_records.plate_color is a varchar,
        // so a SQL JOIN fails on PostgreSQL (uuid = varchar has no operator). We aggregate
        // without a join and resolve plate color names/prices in PHP — portable across drivers.
        $wasteByColor = WasteRecord::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('recorded_at', [$start, $end])
            ->groupBy('plate_color')
            ->get([
                'plate_color as plate_color_id',
                DB::raw('SUM(quantity) as waste_count'),
            ]);

        // ── Production grouped by plate color (denominator for waste rate).
        // Alias the aggregate explicitly — an unaliased SUM() is named differently
        // across drivers ("sum" on PostgreSQL vs "SUM(quantity)" on SQLite).
        $productionByColor = ProductionItem::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('produced_at', [$start, $end])
            ->groupBy('plate_color')
            ->selectRaw('plate_color, SUM(quantity) as total')
            ->pluck('total', 'plate_color');

        // ── Plate color master (name + price), keyed by id.
        // Disaring lewat Uuid::matches dengan alasan yang sama seperti di
        // WasteService: sumbernya kolom varchar, dan satu nilai non-uuid cukup
        // untuk membuat `whereIn` di kolom uuid melempar 22P02.
        $plateColors = PlateColors::query()
            ->whereIn('id', $wasteByColor->pluck('plate_color_id')->filter(
                fn ($value) => Uuid::matches($value)
            )->all())
            ->get()
            ->keyBy('id');

        $totalWaste      = (int) $wasteByColor->sum('waste_count');
        $totalProduction = (int) $productionByColor->sum();
        $wasteCost       = (float) $wasteByColor->sum(function ($row) use ($plateColors) {
            $price = (float) ($plateColors[$row->plate_color_id]->price ?? 0);
            return (int) $row->waste_count * $price;
        });

        $byPlateColor = $wasteByColor->map(function ($row) use ($productionByColor, $plateColors) {
            $waste      = (int) $row->waste_count;
            $production = (int) ($productionByColor[$row->plate_color_id] ?? 0);

            return [
                'plateColorId'    => $row->plate_color_id,
                'plateColorName'  => $plateColors[$row->plate_color_id]->platename ?? 'Unknown',
                'wasteCount'      => $waste,
                'productionCount' => $production,
                'wastePercentage' => $production > 0 ? round(($waste / $production) * 100, 2) : 0,
            ];
        })
            ->sortByDesc('wasteCount')
            ->values();

        // ── Waste grouped by reason
        $byReason = WasteRecord::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('recorded_at', [$start, $end])
            ->groupBy('reason')
            ->get([
                'reason',
                DB::raw('SUM(quantity) as count'),
            ])
            ->map(fn ($row) => [
                'reason'     => $row->reason ?: 'Unknown',
                'count'      => (int) $row->count,
                'percentage' => $totalWaste > 0 ? round(((int) $row->count / $totalWaste) * 100, 2) : 0,
            ])
            ->sortByDesc('count')
            ->values();

        // ── Waste trend per day
        $byDay = WasteRecord::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('recorded_at', [$start, $end])
            ->groupBy(DB::raw('DATE(recorded_at)'))
            ->orderBy(DB::raw('DATE(recorded_at)'))
            ->get([
                DB::raw('DATE(recorded_at) as date'),
                DB::raw('SUM(quantity) as quantity'),
            ])
            ->map(fn ($row) => [
                'date'     => (string) $row->date,
                'quantity' => (int) $row->quantity,
            ])
            ->values();

        return [
            'period' => [
                'startDate' => $start->toDateString(),
                'endDate'   => $end->toDateString(),
            ],
            'totalWaste'      => $totalWaste,
            'totalProduction' => $totalProduction,
            'wastePercentage' => $totalProduction > 0 ? round(($totalWaste / $totalProduction) * 100, 2) : 0,
            'wasteCost'       => $wasteCost,
            'topReason'       => $byReason->first()['reason'] ?? null,
            'byPlateColor'    => $byPlateColor,
            'byReason'        => $byReason,
            'byDay'           => $byDay,
        ];
    }
}
