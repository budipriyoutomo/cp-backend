<?php

namespace App\Http\Controllers;

use App\Http\Requests\Production\ProductionBackdateImportRequest;
use App\Http\Requests\Production\ProductionBackdateTemplateRequest;
use App\Services\ProductionService;
use Symfony\Component\HttpFoundation\Response;

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
     * Template .xlsx berisi menu aktif brand outlet ini plus sheet panduannya.
     *
     * Satu-satunya endpoint di modul ini yang tidak mengembalikan JSON: isinya
     * berkas, dan `Content-Disposition` yang menentukan nama berkas yang dilihat
     * operator. Nama itu ikut menyebut kode outlet — template satu outlet tidak
     * berlaku di outlet brand lain, dan berkas bernama sama di folder unduhan
     * adalah cara paling mudah tertukar.
     */
    public function template(ProductionBackdateTemplateRequest $request): Response
    {
        $template = $this->service->backdateTemplate->build($request->outletId);

        return response($template['contents'], 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $template['filename'] . '"',
            'Content-Length'      => (string) strlen($template['contents']),
            // Tanpa ini nama berkas tidak terbaca fetch/XHR di browser: header
            // yang bukan simple response header disaring CORS kecuali dibuka.
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control'       => 'no-store',
        ]);
    }

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
