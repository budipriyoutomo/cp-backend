<?php

namespace App\Services\Production;

use App\Models\CloseDayLog;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesUserNames;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Laporan audit Tutup Hari & auto-waste. Hanya membaca — barisnya ditulis
 * ProductionItemService::closeDaySold() dan autoWasteCarryOver() di transaksi
 * yang sama dengan UPDATE piringnya.
 */
class CloseDayLogService extends BaseService
{
    use ResolvesUserNames;

    protected string $model = CloseDayLog::class;

    /**
     * @param  array{outletId: string, action?: ?string, startDate?: ?string, endDate?: ?string, per_page?: ?int}  $filters
     */
    public function history(array $filters): LengthAwarePaginator
    {
        $query = CloseDayLog::query()
            ->with('outlet')
            ->where('outlet_id', $filters['outletId']);

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (!empty($filters['startDate'])) {
            $query->whereDate('business_date', '>=', $filters['startDate']);
        }

        if (!empty($filters['endDate'])) {
            $query->whereDate('business_date', '<=', $filters['endDate']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $page = $query
            ->orderByDesc('executed_at')
            ->paginate($perPage);

        $this->attachCreatorNames($page->getCollection());

        return $page;
    }
}
