<?php

namespace App\Services;

use App\Services\Production\ProductionDashboardService;
use App\Services\Production\ProductionPlanService;
use App\Services\Production\WasteRecordService;
use App\Services\Production\ProductionItemService;
use App\Services\Production\WasteService;

class ProductionService
{
    public function __construct(
        public ProductionDashboardService $dashboard,
        public ProductionPlanService $plan,
        public WasteRecordService $waste,
        public ProductionItemService $item,
        public WasteService $wasteService,
    ) {}
}