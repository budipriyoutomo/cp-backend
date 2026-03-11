<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BaseResource extends JsonResource
{
    /**
     * Fields to hide globally (sensitive fields)
     */
    protected array $hiddenFields = [
        'password',
        'remember_token'
    ];

    /**
     * List of fields considered money
     * Example: harga, gaji, total, amount
     */
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
     * Main function to format and cast values
     */
    protected function formatValue($key, $value)
    {
        if ($value === null) return null;

        // BOOLEAN
        if (is_bool($value)) return $value;

        // NUMERIC (int or float)
        if (is_numeric($value)) {

            // Check if money field
            if (in_array($key, $this->moneyFields)) {
                // Format as IDR or clean number
                return (float) $value;
            }

            // Clean integer
            if (ctype_digit(strval($value))) {
                return (int) $value;
            }

            // Float
            return (float) $value;
        }

        // JSON / Array
        if (is_array($value)) return $value;

        // Dates detection
        if ($this->isDate($value)) {
            return Carbon::parse($value)->toDateTimeString();
        }

        // Default string trim
        return trim((string) $value);
    }

    /**
     * Check if a value is a date/datetime
     */
    protected function isDate($value)
    {
        if (!is_string($value)) return false;

        return preg_match(
            '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/',
            $value
        );
    }

    /**
     * Auto-detect attributes and cast them automatically
     */
    protected function autoDetect(): array
    {
        $output = [];

        foreach ($this->resource->toArray() as $key => $value) {

            if (in_array($key, $this->hiddenFields)) continue;

            // Relasi / array
            if (is_array($value)) {
                $output[$key] = $value;
                continue;
            }

            $output[$key] = $this->formatValue($key, $value);
        }

        return $output;
    }


    /**
     * Add system timestamps and userstamps
     */
    protected function systemFields(): array
    {
        return [
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
            'deleted_at' => $this->deleted_at ? $this->deleted_at->toDateTimeString() : null,

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->deleted_by,
        ];
    }

    /**
     * MAIN OUTPUT
     */
    public function toArray($request): array
    {
        return array_merge(
            $this->autoDetect(),
            $this->systemFields()
        );
    }

    /**
     * Standard wrapper
     */
    public function with($request): array
    {
        return [
            'status'  => true,
            'message' => 'Success',
        ];
    }
}
