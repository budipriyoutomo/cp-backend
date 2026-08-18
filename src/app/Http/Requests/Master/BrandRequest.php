<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;

/**
 * `logo` sengaja tidak divalidasi di sini. Kolomnya sudah ada supaya tidak
 * perlu migration lagi nanti, tapi endpoint unggahnya baru dibuat bersama
 * layar admin brand (Fase 5). Menerima string bebas sekarang berarti klien
 * bisa menunjuk path S3 sembarangan.
 */
class BrandRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'code'        => 'required|string|max:50|unique:brands,code',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
        ];
    }

    protected function rulesForUpdate(): array
    {
        return [
            'code'        => 'required|string|max:50|unique:brands,code,' . $this->route('id'),
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
        ];
    }
}
