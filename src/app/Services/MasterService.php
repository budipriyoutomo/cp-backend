<?php

namespace App\Services;

use App\Services\Master\PlateColorService;
use App\Services\Master\MenuService;
use App\Services\Master\OutletService;

class MasterService
{
    public function __construct(
        public PlateColorService $plateColor,
        public MenuService $menu,
        public OutletService $outlet
    ) {}
}
