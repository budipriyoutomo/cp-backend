<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait HasUserstamps
{
    public static function bootHasUserstamps()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        // Jika memakai soft deletes + deleted_by
        if (method_exists(self::class, 'bootSoftDeletes')) {
            static::deleting(function ($model) {
                if (Auth::check() && !$model->isForceDeleting()) {
                    $model->deleted_by = Auth::id();
                    $model->save();
                }
            });
        }
    }

    // Kolom harus bisa di-mass assign
    public function initializeHasUserstamps()
    {
        $this->fillable[] = 'created_by';
        $this->fillable[] = 'updated_by';

        // Jika memakai deleted_by
        if (!in_array('deleted_by', $this->fillable)) {
            $this->fillable[] = 'deleted_by';
        }
    }
}
