<?php

namespace App\Services;

use App\Services\Master\BrandService;
use App\Services\Master\PlateColorService;
use App\Services\Master\MenuService;
use App\Services\Master\OutletService;
use App\Services\Master\TimeMarkerService;
use App\Services\Master\TimeSlotService;
use App\Services\Master\WasteReasonService;

class MasterService
{
    public function __construct(
        public PlateColorService $plateColor,
        public MenuService $menu,
        public OutletService $outlet,
        public WasteReasonService $wasteReason,
        public BrandService $brand,
        public TimeMarkerService $timeMarker,
        public TimeSlotService $timeSlot
    ) {}
}
