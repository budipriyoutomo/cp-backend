<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * `POST /production/mark-sold` dan `POST /production/mark-waste`.
 *
 * Dulu kedua endpoint ini tanpa validasi sama sekali: `itemIds` kosong atau
 * bukan array menjawab 500 (TypeError di service), dan tanpa `outletId`
 * middleware `outlet.access` tidak memeriksa apa pun.
 *
 * KEPUTUSAN TRANSISI (outletId `sometimes`, bukan `required`):
 * Request lama di antrean offline tablet (IndexedDB) tidak membawa `outletId`.
 * 422 adalah error non-transien, jadi kalau `outletId` langsung wajib, antrean
 * itu dibuang diam-diam setelah deploy — piring yang sudah ditandai dapur
 * hilang. Selama transisi, request tanpa `outletId` tetap diterima: outletnya
 * diturunkan dari item (AuthorizesProductionItems), lalu izin user tetap
 * diperiksa lewat OutletGate — tidak ada celah lintas outlet.
 * Rilis berikutnya (setelah semua tablet memakai frontend baru dan antreannya
 * kosong): ganti `sometimes` menjadi `required` di sini,
 * ProductionExpiredBulkChangeRequest, dan WasteRecordRequest.
 */
class MarkProductionItemsRequest extends BaseRequest
{
    public const ITEM_RULES = [
        // Batas atas sama dengan expired/bulk: satu batch di lapangan puluhan
        // piring, bukan ratusan.
        'itemIds'   => ['required', 'array', 'min:1', 'max:500'],
        'itemIds.*' => ['required', 'uuid', 'distinct'],
    ];

    /** `uuid` sebelum `exists`: tanpa penjaga bentuk, validatornya menjawab 500. */
    public const OUTLET_RULES = [
        'outletId' => ['sometimes', 'uuid', 'exists:outlets,id'],
    ];

    protected function rulesForCreate(): array
    {
        return self::OUTLET_RULES + self::ITEM_RULES;
    }
}
