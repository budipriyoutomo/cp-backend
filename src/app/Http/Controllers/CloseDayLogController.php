<?php

namespace App\Http\Controllers;

use App\Http\Requests\Production\CloseDayLogQueryRequest;
use App\Http\Resources\Production\CloseDayLogResource;
use App\Services\ProductionService;

/**
 * Laporan audit Tutup Hari (`POST /production/close-day`) dan auto-waste
 * (`production:close-stale`, gerbang Get Data POS). Layar `/report/close-day-logs`.
 */
class CloseDayLogController extends BaseApiController
{
    public function __construct(
        protected ProductionService $service,
    ) {}

    public function index(CloseDayLogQueryRequest $request)
    {
        return $this->resource(
            CloseDayLogResource::collection(
                $this->service->closeDayLog->history($request->validated())
            )
        );
    }
}
