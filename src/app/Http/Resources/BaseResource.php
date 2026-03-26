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

    protected function formatValue($key, $value)
    {
        if ($value === null) return null;

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