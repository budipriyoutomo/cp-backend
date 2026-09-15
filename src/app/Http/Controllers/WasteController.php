<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\Production\WasteResource;
use App\Models\WasteRecord;
use App\Services\ProductionService;
use Illuminate\Http\Request;

class WasteController extends BaseApiController
{
    public function __construct(
        protected ProductionService $service,
    ) {}

    /**
     * GET /api/waste
     */
    public function index(Request $request)
    {
        $request->validate([
            'outletId' => ['required', 'uuid'],
            'date' => ['required', 'date'],
            'plateColorId' => ['nullable'],
        ]);

        $data = $this->service->wasteReport->getAll(
            $request->only([
                'outletId',
                'date',
                'plateColorId',
            ])
        );

        return $this->success(
            WasteResource::collection($data),
            'Waste data fetched successfully'
        );
    }

    /**
     * GET /api/waste/summary
     */
    public function summary(Request $request)
    {
        $request->validate([
            'outletId' => ['required', 'uuid'],
            'date' => ['required', 'date'],
        ]);

        $data = $this->service->wasteReport->getSummary(
            $request->only([
                'outletId',
                'date',
            ])
        );

        return $this->success(
            $data,
            'Waste summary fetched successfully'
        );
    }

    /**
     * GET /api/waste/{waste}
     */
    public function show(WasteRecord $waste)
    {
        $data = $this->service->wasteReport->getById($waste);

        return $this->success(
            new WasteResource($data),
            'Waste detail fetched successfully'
        );
    }
}