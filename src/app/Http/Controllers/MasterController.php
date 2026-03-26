<?php

namespace App\Http\Controllers;
 
use App\Http\Requests\Master\PlateColorRequest;
use App\Http\Resources\Master\PlateColorResource;
use App\Http\Requests\Master\MenuRequest;
use App\Http\Resources\Master\MenuResource;
use App\Http\Requests\Master\OutletRequest;
use App\Http\Resources\Master\OutletResource;

use App\Services\MasterService;
use Symfony\Component\HttpFoundation\Request;

class MasterController extends BaseApiController
{
    public function __construct(
        private MasterService $service
    ) {}
  
    // ======================================================
    // PLATE COLOR METHODS
    // ======================================================

    public function platecolorindex(Request $request)
    {
        return $this->resource(
            PlateColorResource::collection($this->service->plateColor->list($request))
        );
    }

    public function platecolorstore(PlateColorRequest $request)
    {
        return $this->resource(
            new PlateColorResource(
                $this->service->plateColor->create($request->validated())
            ),
            'Plate color created',
            201
        );
    }

    public function platecolorupdate(PlateColorRequest $request, $id)
    {
        return $this->resource(
            new PlateColorResource(
                $this->service->plateColor->update($id, $request->validated())
            ),
            'Plate color updated'
        );
    }

    public function platecolordestroy($id)
    {
        $this->service->plateColor->delete($id);
        return $this->success(null, 'Plate color deleted');
    }

    
    // ======================================================
    // MENU METHODS
    // ======================================================

    public function menuindex(Request $request)
    {
        return $this->resource(
            MenuResource::collection($this->service->menu->list($request))
        );
    }

    public function menustore(MenuRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }
        
        return $this->resource(
            new MenuResource(
                $this->service->menu->create($data)
            ),
            'Menu created',
            201
        );
    }

    public function menuupdate(MenuRequest $request, $id)
    {
        return $this->resource(
            new MenuResource(
                $this->service->menu->update($id, $request->validated())
            ),
            'Menu updated'
        );
    }

    public function menudestroy($id)
    {
        $this->service->menu->delete($id);
        return $this->success(null, 'Menu deleted');
    }

    
    // ======================================================
    // OUTLET METHODS
    // ======================================================

    public function outletindex(Request $request)
    {
        return $this->resource(
            OutletResource::collection($this->service->outlet->list($request))
        );
    }

    public function outletstore(OutletRequest $request)
    {
        return $this->resource(
            new OutletResource(
                $this->service->outlet->create($request->validated())
            ),
            'Outlet created',
            201
        );
    }

    public function outletupdate(OutletRequest $request, $id)
    {
        return $this->resource(
            new OutletResource(
                $this->service->outlet->update($id, $request->validated())
            ),
            'Outlet updated'
        );
    }

    public function outletdestroy($id)
    {
        $this->service->outlet->delete($id);
        return $this->success(null, 'Outlet deleted');
    }
}
