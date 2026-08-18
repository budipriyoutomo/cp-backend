<?php

namespace Tests\Concerns;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\ProductionItem;

/**
 * Helpers to seed the master data that the Production module depends on.
 *
 * Foreign keys are enforced on the SQLite test connection, so production
 * items/records need a real Outlet, PlateColor and Menu to reference.
 *
 * Everything hangs off one default brand, created lazily and reused, so the
 * shape matches production: one outlet belongs to one brand, and its menus and
 * plate colors belong to that same brand. Tests that need a second brand pass
 * one in explicitly.
 */
trait SeedsProductionData
{
    private ?Brand $defaultBrand = null;

    protected function createBrand(array $overrides = []): Brand
    {
        return Brand::create(array_merge([
            'code'      => 'MHR',
            'name'      => 'Maharasa',
            'is_active' => true,
        ], $overrides));
    }

    protected function defaultBrand(): Brand
    {
        return $this->defaultBrand ??= $this->createBrand();
    }

    protected function createOutlet(array $overrides = []): Outlet
    {
        return Outlet::create(array_merge([
            'code'      => 'BDG',
            'name'      => 'Bandung',
            'brand'     => 'Maharasa',
            'brand_id'  => $this->defaultBrand()->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function createPlateColor(array $overrides = []): PlateColors
    {
        return PlateColors::create(array_merge([
            'platename' => 'Merah',
            'brand_id'  => $this->defaultBrand()->id,
            'price'     => 15000,
            'is_active' => true,
        ], $overrides));
    }

    protected function createMenu(?PlateColors $plateColor = null, array $overrides = []): Menu
    {
        $plateColor ??= $this->createPlateColor();

        return Menu::create(array_merge([
            'menuname'       => 'Sushi Salmon',
            'price'          => 20000,
            'shelf_life'     => 60,
            'plate_color_id' => $plateColor->id,
            'brand_id'       => $plateColor->brand_id,
            'is_active'      => true,
        ], $overrides));
    }

    protected function createProductionItem(Outlet $outlet, Menu $menu, array $overrides = []): ProductionItem
    {
        return ProductionItem::create(array_merge([
            'menu_id'     => $menu->id,
            'outlet_id'   => $outlet->id,
            'plate_color' => $menu->plate_color_id,
            'quantity'    => 1,
            'produced_at' => now(),
            'expires_at'  => now()->addMinutes(60),
            'belt_status' => 'fresh',
        ], $overrides));
    }
}
