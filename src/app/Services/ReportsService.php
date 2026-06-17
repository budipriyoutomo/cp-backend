<?php

namespace App\Services;
 
use App\Services\Reports\DailySummaryService;
use App\Services\Reports\WasteAnalysisService;

class ReportsService
{
    public function __construct(
        public DailySummaryService $dailySummary,
        public WasteAnalysisService $wasteAnalysis,
    ) {}
}