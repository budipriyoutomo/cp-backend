<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClosingReport\ClosingReportDataRequest;
use App\Http\Requests\ClosingReport\StoreClosingReportRequest;
use App\Http\Requests\ClosingReport\SubmitClosingReportRequest;
use App\Http\Requests\ClosingReport\UploadWastePhotosRequest;
use App\Http\Resources\ClosingReport\ClosingReportResource;
use App\Services\ClosingReport\ClosingReportService;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClosingReportController extends BaseApiController
{
    public function __construct(
        protected ClosingReportService $service,
        protected SalesService $salesService
    ) {}

    public function index(Request $request)
    {
        return $this->resource(
            ClosingReportResource::collection($this->service->list($request))
        );
    }

    public function data(ClosingReportDataRequest $request)
    {
        $validated = $request->validated();

        $reportData = $this->salesService->getClosingReportData(
            $validated['outletId'],
            $validated['date']
        );

        if (!$reportData['status']) {
            return $this->error($reportData['message'], 404);
        }

        return $this->success($reportData['data'], $reportData['message']);
    }

    public function show(string $id)
    {
        return $this->resource(
            new ClosingReportResource($this->service->show($id))
        );
    }

    public function submit(SubmitClosingReportRequest $request)
    {
        $report = $this->service->submit($request->validated());

        return $this->resource(
            new ClosingReportResource($report),
            'Closing report submitted'
        );
    }

    public function uploadWastePhotos(UploadWastePhotosRequest $request)
    {
        $urls = collect($request->file('photos', []))
            ->map(function ($file) {
                $path = $file->store('closing-report/waste-photos', 'public');

                return Storage::disk('public')->url($path);
            })
            ->values()
            ->all();

        return $this->success(['urls' => $urls], 'Photos uploaded');
    }

    public function destroy(string $id)
    {
        $this->service->delete($id);

        return $this->success(null, 'Draft deleted');
    }
}
