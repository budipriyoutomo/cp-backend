<?php

namespace App\Traits;

trait HasActive
{
    public static function bootHasActive()
    {
        static::creating(function ($model) {
            if ($model->is_active === null) {
                $model->is_active = true;
            }
        });
    }

    public function initializeHasActive()
    {
        $this->casts['is_active'] = 'boolean';

        if (!in_array('is_active', $this->fillable)) {
            $this->fillable[] = 'is_active';
        }
    }

    // Query Scope
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    // Helpers
    public function activate()
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate()
    {
        $this->update(['is_active' => false]);
    }
}
