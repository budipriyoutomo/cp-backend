<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class BaseResource extends JsonResource
{
    protected array $hiddenFields = [
        'password',
        'remember_token'
    ];

    protected array $moneyFields = [
        'harga',
        'gaji',
        'salary',
        'total',
        'amount',
        'nominal',
        'subtotal',
        'grandtotal',
    ];

    /**
     * Kolom yang harus tetap teks, betapa pun isinya mirip angka.
     *
     * `formatValue()` menebak tipe dari ISI nilai, bukan dari kolomnya. Untuk
     * kolom `varchar` yang kebetulan berisi digit, tebakan itu selalu salah dan
     * merusak dua hal sekaligus:
     *
     * - Pemakai menerima number, bukan string. `menus.code = "12345"` datang
     *   sebagai `12345`, dan frontend yang memanggil `.toLowerCase()` di atasnya
     *   langsung mati.
     * - Nol di depan hilang. `"007"` lolos `ctype_digit()` lalu jadi `7`.
     *   Ini bukan sekadar salah tipe — itu nilai yang berbeda.
     *
     * Daftarnya sengaja opt-in per resource: menonaktifkan tebakan itu secara
     * global akan mengubah bentuk respons setiap endpoint sekaligus, termasuk
     * `price` yang memang bergantung padanya (cast `decimal:2` mengembalikan
     * string dari Eloquent, dan pemakainya mengharapkan angka).
     *
     * Hanya resource yang benar-benar melewatkan nilai ke `formatValue()` yang
     * perlu mengisinya — lewat `autoDetect()` atau memanggilnya sendiri.
     * Resource yang menyusun `toArray()` sepenuhnya manual dari atribut model
     * (`ProductionItemResource`, `WasteRecordResource`, `WasteResource`,
     * `ProductionMenuResource`, `ClosingReportResource`,
     * `ClosingReportEntryResource`, `SalesDraftResource`,
     * `SalesClosingReportResource`) tidak pernah melewati fungsi ini, jadi
     * nilainya sudah apa adanya. Relasi yang ter-load juga aman: `autoDetect()`
     * meneruskan array mentah tanpa memformatnya.
     *
     * @var array<int, string>
     */
    protected array $textFields = [];

    protected function formatValue($key, $value)
    {
        if ($value === null) return null;

        // Dijaga di sini, bukan di autoDetect(): sebagian resource memanggil
        // formatValue() langsung per-field (lihat DailySummaryResource), jadi
        // penjagaan di pemanggil saja akan bocor di jalur itu.
        if (in_array($key, $this->textFields, true)) {
            return trim((string) $value);
        }

        if (is_bool($value)) return $value;

        if (is_numeric($value)) {

            if (in_array($key, $this->moneyFields)) {
                return (float) $value;
            }

            if (ctype_digit(strval($value))) {
                return (int) $value;
            }

            return (float) $value;
        }

        if (is_array($value)) return $value;

        if ($this->isDate($value)) {
            return Carbon::parse($value)->toDateTimeString();
        }

        return trim((string) $value);
    }

    protected function isDate($value)
    {
        if (!is_string($value)) return false;

        return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value);
    }

    protected function autoDetect(): array
    {
        $output = [];

        foreach ($this->resource->toArray() as $key => $value) {

            if (in_array($key, $this->hiddenFields)) continue;

            $output[$key] = is_array($value)
                ? $value
                : $this->formatValue($key, $value);
        }

        return $output;
    }

    protected function systemFields(): array
    {
        return [
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),

            'created_by' => trim((string) $this->created_by),
            'updated_by' => trim((string) $this->updated_by),
            'deleted_by' => $this->deleted_by,
        ];
    }

    public function toArray($request): array
    {
        return array_merge(
            $this->autoDetect(),
            $this->systemFields()
        );
    }

    /**
     * 🔥 GLOBAL WRAPPER (single + collection)
     */
    public function with($request): array
    {
        return [
            'status'  => true,
            'message' => 'Success',
        ];
    }
 
}