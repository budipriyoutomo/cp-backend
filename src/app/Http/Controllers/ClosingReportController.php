<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClosingReport\ClosingReportDataRequest;
use App\Http\Requests\ClosingReport\StoreClosingReportRequest;
use App\Http\Requests\ClosingReport\SubmitClosingReportRequest;
use App\Http\Requests\ClosingReport\UploadWastePhotosRequest;
use App\Http\Resources\ClosingReport\ClosingReportResource;
use App\Services\ClosingReport\ClosingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClosingReportController extends BaseApiController
{
    public function __construct(
        protected ClosingReportService $service
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

        $report = $this->service->getData(
            $validated['outletId'],
            $validated['date']
        );

        return $this->resource(new ClosingReportResource($report));
    }

    public function show(string $id)
    {
        return $this->resource(
            new ClosingReportResource($this->service->show($id))
        );
    }

    public function storeDraft(StoreClosingReportRequest $request)
    {
        $report = $this->service->saveDraft($request->validated());

        return $this->resource(
            new ClosingReportResource($report),
            'Draft saved',
            201
        );
    }

    public function updateDraft(string $id, StoreClosingReportRequest $request)
    {
        $report = $this->service->saveDraft($request->validated(), $id);

        return $this->resource(
            new ClosingReportResource($report),
            'Draft updated'
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
