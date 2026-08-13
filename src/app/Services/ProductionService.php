<?php

namespace App\Services;

use App\Services\Production\ProductionDashboardService;
use App\Services\Production\ProductionPlanService;
use App\Services\Production\WasteRecordService;
use App\Services\Production\ProductionItemService;
use App\Services\Production\WasteService;

/**
 * Aggregator: no logic of its own, just the sub-services the controllers need.
 *
 * `wasteRecord` writes waste_records, `wasteReport` reads and aggregates them.
 * They used to be called `waste` and `wasteService`, where the shorter name was
 * the less common one — easy to reach for the wrong half and get a method-not-found.
 */
class ProductionService
{
    public function __construct(
        public ProductionDashboardService $dashboard,
        public ProductionPlanService $plan,
        public WasteRecordService $wasteRecord,
        public ProductionItemService $item,
        public WasteService $wasteReport,
    ) {}
}