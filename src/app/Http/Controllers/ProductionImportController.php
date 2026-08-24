<?php

namespace App\Http\Controllers;

use App\Http\Requests\Production\ProductionBackdateImportRequest;
use App\Services\ProductionService;

/**
 * Import produksi backdate. Terpisah dari ProductionController karena jalurnya
 * memang lain: hanya admin, hanya tanggal yang sudah lewat, dan berkas — bukan
 * aksi dapur yang berjalan sepanjang shift.
 *
 * Semua aturannya ada di ProductionBackdateImportService.
 */
class ProductionImportController extends BaseApiController
{
    public function __construct(
        protected ProductionService $service,
    ) {}

    /**
     * Dry run: baca, validasi, hitung tabrakan. Tidak menulis apa pun.
     */
    public function preview(ProductionBackdateImportRequest $request)
    {
        $data = $this->service->backdateImport->preview(
            $request->file('file'),
            $request->outletId
        );

        return $this->success($data);
    }

    public function store(ProductionBackdateImportRequest $request)
    {
        $result = $this->service->backdateImport->import(
            $request->file('file'),
            $request->outletId,
            $request->boolean('allowDuplicate')
        );

        return $this->success(
            $result,
            "{$result['imported']} piring diimpor"
        );
    }
}
