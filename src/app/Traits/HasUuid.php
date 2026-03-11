<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            if (!$model->getKey()) {
                $model->{$model->getKeyName()} = Str::uuid()->toString();
            }
        });
    }

    public function initializeHasUuid()
    {
        // Pastikan key bukan auto increment
        $this->incrementing = false;

        // Pastikan key type string
        $this->keyType = 'string';
    }
}
