<?php

namespace App\Services;
 
use App\Services\Reports\DailySummaryService;

class ReportsService
{
    public function __construct(
        public DailySummaryService $dailySummary, 
    ) {}
}