<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * Trait NormalizeMySqlDates
 *
 * Menormalisasi atribut tanggal/waktu menjadi format MySQL standar:
 *  - 'date'     => 'Y-m-d'
 *  - 'datetime' => 'Y-m-d H:i:s'
 *  - 'timestamp'=> 'Y-m-d H:i:s'
 *
 * Mode:
 *  - Jika model mendefinisikan protected $isoDateAttributes (array),
 *    trait akan gunakan mapping tersebut. Format value bisa 'date','datetime','timestamp', atau custom PHP date format.
 *  - Jika tidak ada, trait akan mendeteksi dari $casts model (casts yang mengandung 'date'|'datetime'|'timestamp')
 *
 * Usage (example in model):
 *   use NormalizeMySqlDates;
 *   protected $isoDateAttributes = [
 *     'tanggallahir' => 'date',
 *     'waktu_absen'  => 'datetime'
 *   ];
 */
trait NormalizeMySqlDates
{
    public static function bootNormalizeMySqlDates(): void
    {
        static::saving(function ($model) {
            $model->normalizeMySqlDates();
        });
    }

    /**
     * Normalize date/datetime attributes to MySQL standard formats.
     */
    public function normalizeMySqlDates(): void
    {
        // Determine attributes to normalize:
        // 1) explicit mapping via $isoDateAttributes in model
        // 2) otherwise auto-detect from $casts
        $mapping = [];

        if (property_exists($this, 'isoDateAttributes') && is_array($this->isoDateAttributes) && count($this->isoDateAttributes) > 0) {
            // normalize keys: if given as simple array like ['tanggallahir','tanggalbergabung']
            foreach ($this->isoDateAttributes as $k => $v) {
                if (is_int($k) && is_string($v)) {
                    $mapping[$v] = 'date'; // default date if no type provided
                } else {
                    $mapping[$k] = $v ?: 'date';
                }
            }
        } else {
            // auto-detect from casts
            return;
            /*
            $casts = method_exists($this, 'getCasts') ? $this->getCasts() : (property_exists($this, 'casts') ? $this->casts : []);
            foreach ($casts as $attr => $castType) {
                $lower = strtolower($castType);
                if (str_contains($lower, 'datetime') || str_contains($lower, 'timestamp')) {
                    $mapping[$attr] = 'datetime';
                } elseif (str_contains($lower, 'date')) {
                    $mapping[$attr] = 'date';
                }
            }*/
        }

        if (empty($mapping)) {
            return;
        }

        foreach ($mapping as $attr => $type) {
            // skip if attribute not present in incoming attributes (no change)
            // get raw incoming attributes array
            $raw = $this->getAttributes();

            // If attribute not present in current input, skip
            if (!array_key_exists($attr, $raw) && $this->getAttribute($attr) === null) {
                continue;
            }

            $val = $this->getAttribute($attr);

            // treat empty string / false => null
            if ($val === '' || $val === false) {
                $this->attributes[$attr] = null;
                continue;
            }

            if ($val === null) {
                $this->attributes[$attr] = null;
                continue;
            }

            // If it's already a DateTime/Carbon instance, use it
            if ($val instanceof \DateTime) {
                $dt = $val;
            } else {
                // Try parse using Carbon
                try {
                    $dt = Carbon::parse($val);
                } catch (\Throwable $e) {
                    // parse fail -> set null to avoid SQL error (change if you prefer keep raw)
                    $this->attributes[$attr] = null;
                    continue;
                }
            }

            // Resolve final format
            $format = 'Y-m-d'; // default for 'date'
            $typeLower = strtolower($type);
            if ($typeLower === 'datetime' || $typeLower === 'timestamp') {
                $format = 'Y-m-d H:i:s';
            } elseif ($typeLower === 'date') {
                $format = 'Y-m-d';
            } elseif (is_string($type) && $type !== '') {
                // custom PHP date format supplied
                $format = $type;
            }

            $this->attributes[$attr] = $dt->format($format);
        }
    }
}
