<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * Menutup satu batch piring expired sekaligus.
 *
 * Pasangannya `ProductionExpiredChangeRequest` yang per-piring. Yang ini POST,
 * jadi rule-nya masuk lewat `rulesForCreate()` — `BaseRequest::rules()` memilih
 * berdasarkan verb HTTP, bukan nama method.
 *
 * `notes` wajib untuk waste: alasan waste ikut ke `waste_records` dan dari sana
 * ke laporan analisis waste. Waste tanpa alasan adalah baris laporan yang tidak
 * bisa ditindaklanjuti siapa pun.
 */
class ProductionExpiredBulkChangeRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            // Batas atas menjaga satu request tidak menahan transaksi terlalu
            // lama; satu batch produksi di lapangan puluhan piring, bukan ratusan.
            'itemIds'   => ['required', 'array', 'min:1', 'max:500'],
            'itemIds.*' => ['required', 'uuid', 'distinct'],
            'status'    => ['required', 'in:sold,waste'],
            'notes'     => ['required_if:status,waste', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required_if' => 'Alasan waste wajib diisi.',
        ];
    }
}
