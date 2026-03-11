<?php

namespace App\Services\Master;

use App\Models\Menu;
use App\Services\BaseService; 

class MenuService extends BaseService
{
    protected string $model = Menu::class;
}