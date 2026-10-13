<?php

namespace App\Http\Controllers;

use App\Http\Requests\Production\ProductionCorrectionQueryRequest;
use App\Http\Requests\Production\ProductionCorrectionRequest;
use App\Http\Resources\Production\ProductionCorrectionResource;
use App\Services\ProductionService;

/**
 * Koreksi Status Produksi (sold ⇄ waste) untuk piring yang sudah final.
 * Hanya admin (`role:admin` + `module:admin`), sama seperti import backdate.
 *
 * Semua aturannya ada di ProductionStatusCorrectionService.
 */
class ProductionCorrectionController extends BaseApiController
{
    public function __construct(
        protected ProductionService $service,
    ) {}

    public function summary(ProductionCorrectionQueryRequest $request)
    {
        return $this->success(
            $this->service->correction->summary($request->outletId, $request->date)
        );
    }

    /**
     * Dry run: hitung sebelum/sesudah. Tidak menulis apa pun.
     */
    public function preview(ProductionCorrectionRequest $request)
    {
        return $this->success(
            $this->service->correction->preview($request->validated())
        );
    }

    public function store(ProductionCorrectionRequest $request)
    {
        $correction = $this->service->correction->apply($request->validated());

        $total = $correction->total_to_waste + $correction->total_to_sold;

        return $this->success(
            (new ProductionCorrectionResource($correction))->withItems()->resolve(),
            "{$total} piring dikoreksi"
        );
    }

    public function index(ProductionCorrectionQueryRequest $request)
    {
        return $this->resource(
            ProductionCorrectionResource::collection(
                $this->service->correction->history($request->validated())
            )
        );
    }

    public function show(string $id)
    {
        return $this->success(
            (new ProductionCorrectionResource($this->service->correction->show($id)))
                ->withItems()
                ->resolve()
        );
    }
}
