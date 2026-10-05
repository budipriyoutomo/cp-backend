<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClosingReport\ClosingReportOutboxIndexRequest;
use App\Http\Resources\ClosingReport\ClosingReportOutboxResource;
use App\Models\ClosingReportOutbox;
use App\Services\ClosingReport\ClosingReportOutboxService;

/**
 * Layar admin "Kirim Ulang BI" — daftar pesan closing report ke BI dan tombol
 * kirim ulang. Hanya admin (`role:admin` + `module:admin`), sama seperti
 * import backdate. Aturannya ada di ClosingReportOutboxService.
 */
class ClosingReportOutboxController extends BaseApiController
{
    public function __construct(
        protected ClosingReportOutboxService $service,
    ) {}

    public function index(ClosingReportOutboxIndexRequest $request)
    {
        return $this->resource(
            ClosingReportOutboxResource::collection($this->service->list($request->validated()))
        );
    }

    public function resend(string $id)
    {
        $row = $this->service->resend(ClosingReportOutbox::findOrFail($id));

        return $this->resource(
            new ClosingReportOutboxResource($row),
            $row->status === ClosingReportOutbox::STATUS_PUBLISHED
                ? 'Terkirim ke BI'
                : 'Belum terkirim, akan dicoba ulang otomatis'
        );
    }
}
