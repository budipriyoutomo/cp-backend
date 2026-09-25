<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Concerns\ScopedToBrand;

class TimeMarkerRequest extends BaseRequest
{
    use ScopedToBrand;

    /** '#RRGGBB'. Bentuk pendek '#RGB' ditolak supaya penyimpanan seragam. */
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    protected function prepareForValidation()
    {
        parent::prepareForValidation();

        $this->fillSoleBrand();
    }

    /**
     * `brand_id` di sini WAJIB, tidak memakai `brandIdRules()`.
     *
     * Aturan itu membolehkan NULL demi instalasi yang belum punya brand sama
     * sekali — masuk akal untuk menu dan plate color, yang memang harus bisa
     * diisi lebih dulu. Tapi kolom `time_markers.brand_id` NOT NULL, jadi
     * payload tanpa brand akan lolos validasi lalu mati sebagai 500 di basis
     * data. Lebih baik ditolak 422 dengan pesan yang jelas.
     */
    private function brandRules(): array
    {
        return ['required', 'uuid', 'exists:brands,id'];
    }

    protected function rulesForCreate(): array
    {
        return [
            'brand_id'   => $this->brandRules(),
            'label'      => ['required', 'string', 'max:50', $this->uniqueWithinBrand('time_markers', 'label')],
            'color_hex'  => ['required', 'string', self::HEX],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function rulesForUpdate(): array
    {
        return [
            'brand_id'   => $this->brandRules(),
            'label'      => ['required', 'string', 'max:50', $this->uniqueWithinBrand('time_markers', 'label', $this->route('id'))],
            'color_hex'  => ['required', 'string', self::HEX],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'color_hex.regex' => 'Warna harus dalam bentuk #RRGGBB, misalnya #3B82F6.',
        ];
    }
}
