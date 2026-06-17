<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reports\DailySummaryRequest;
use App\Http\Requests\Reports\WasteAnalysisRequest;
use App\Http\Resources\Reports\DailySummaryResource;

use App\Services\ReportsService;

class ReportsController extends BaseApiController
{
    public function __construct(
        protected ReportsService $service,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | DAILY SUMMARY
    |--------------------------------------------------------------------------
    */
    public function dailySummary(DailySummaryRequest $request)
    {
        $data = $this->service->dailySummary->get(
            $request->outletId,
            $request->date
        );

        return $this->success(
            new DailySummaryResource($data)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | WASTE ANALYSIS
    |--------------------------------------------------------------------------
    */
    public function wasteAnalysis(WasteAnalysisRequest $request)
    {
        $data = $this->service->wasteAnalysis->get(
            $request->outletId,
            $request->startDate,
            $request->endDate
        );

        return $this->success($data);
    }
}