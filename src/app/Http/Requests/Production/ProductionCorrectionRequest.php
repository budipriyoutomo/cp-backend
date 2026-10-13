<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * Dipakai preview maupun simpan Koreksi Status Produksi — keduanya menerima
 * body yang sama. Aturan yang butuh data (jumlah tersedia, kunci tahap hilir,
 * alasan waste wajib untuk arah ke waste) ada di
 * ProductionStatusCorrectionService.
 */
class ProductionCorrectionRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId'         => ['required', 'uuid', 'exists:outlets,id'],
            'date'             => ['required', 'date_format:Y-m-d'],
            'reason'           => ['required', 'string', 'min:10', 'max:500'],
            'wasteReasonId'    => ['nullable', 'uuid', 'exists:waste_reasons,id'],

            'items'            => ['required', 'array', 'min:1', 'max:200'],
            'items.*.menuId'   => ['required', 'uuid'],
            'items.*.from'     => ['required', 'in:sold,waste'],
            'items.*.to'       => ['required', 'in:sold,waste', 'different:items.*.from'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'outletId'         => 'outlet',
            'date'             => 'tanggal',
            'reason'           => 'alasan koreksi',
            'wasteReasonId'    => 'alasan waste',
            'items'            => 'daftar koreksi',
            'items.*.quantity' => 'jumlah',
        ];
    }
}
