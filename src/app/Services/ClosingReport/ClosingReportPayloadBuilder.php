<?php

namespace App\Services\ClosingReport;

use App\Models\ClosingReport;
use App\Models\ProductionItem;
use App\Models\SalesItemDetail;
use Illuminate\Support\Carbon;

/**
 * Payload `closingreport.submitted` v1 untuk BI. Bentuknya kontrak dengan
 * pihak luar — mengubah field berarti menaikkan `version`.
 *
 * `sentAt` sengaja tidak diisi di sini: payload disimpan di outbox saat submit,
 * sedangkan waktu kirim baru diketahui saat publish (bisa jauh setelahnya kalau
 * broker sempat mati). Lihat ClosingReportOutboxService::publish().
 */
class ClosingReportPayloadBuilder
{
    public const EVENT = 'closingreport.submitted';
    public const VERSION = 1;

    public function build(ClosingReport $report): array
    {
        $report->loadMissing('outlet.brandMaster');

        $outlet = $report->outlet;
        $brand = $outlet->brandMaster;

        return [
            'event'     => self::EVENT,
            'version'   => self::VERSION,
            'messageId' => $report->id,
            'data'      => [
                'closingReportId' => $report->id,
                'date'            => $report->date->toDateString(),
                'outlet'          => ['code' => $outlet->code, 'name' => $outlet->name],
                'brand'           => $brand ? ['code' => $brand->code, 'name' => $brand->name] : null,
                'items'           => $this->items($report),
            ],
        ];
    }

    private function items(ClosingReport $report): array
    {
        $details = SalesItemDetail::query()
            ->whereHas('item', fn ($q) => $q->where('sales_id', $report->sales_id))
            ->with(['menu' => fn ($q) => $q->withTrashed()])
            ->orderBy('menu_name')
            ->get();

        $productionDates = $this->productionDates($report, $details->pluck('menu_id')->unique()->all());

        return $details
            ->map(fn (SalesItemDetail $detail) => [
                'menuId'         => $detail->menu_id,
                'menuCode'       => $detail->menu?->code,
                'menuName'       => $detail->menu_name,
                'productionDate' => $productionDates[$detail->menu_id] ?? null,
                'sold'           => (int) $detail->total_sold,
                'waste'          => (int) $detail->total_wasted,
                'adjustment'     => (int) $detail->adjustment,
                'compensation'   => (int) $detail->compensation,
            ])
            ->values()
            ->all();
    }

    /**
     * Tanggal produksi per menu, dibaca dari production_items — bukan disalin
     * dari tanggal closing.
     *
     * Filternya sama dengan yang dipakai saat angka per menu dikumpulkan
     * (ProductionItemService::getProductionMenuDetail: `whereDate(produced_at)`
     * di zona waktu aplikasi), jadi tanggal ini selalu menunjuk baris produksi
     * yang benar-benar diwakili angka sold/waste-nya.
     *
     * @return array<string, string> menu_id => Y-m-d
     */
    private function productionDates(ClosingReport $report, array $menuIds): array
    {
        if ($menuIds === []) {
            return [];
        }

        return ProductionItem::query()
            ->where('outlet_id', $report->outlet_id)
            ->whereIn('menu_id', $menuIds)
            ->whereDate('produced_at', $report->date->toDateString())
            ->groupBy('menu_id')
            ->selectRaw('menu_id, MIN(produced_at) as first_produced_at')
            ->pluck('first_produced_at', 'menu_id')
            ->map(fn ($producedAt) => Carbon::parse($producedAt)->toDateString())
            ->all();
    }
}
