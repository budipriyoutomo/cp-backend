<?php 

namespace App\Models;
 
class ProductionItem extends BaseModel
{ 
    protected $table = 'production_items';

    protected $fillable = [
        'menu_id',
        'outlet_id',
        'plate_color',
        'quantity',
        'produced_at',
        'expires_at',
        'belt_status',
        'final_status',
        'sold_at',
        'wasted_at', 
        'notes',
    ];

    protected $casts = [
        'produced_at' => 'datetime',
        'expires_at' => 'datetime',
        'sold_at' => 'datetime',
        'wasted_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATION
    |--------------------------------------------------------------------------
    */

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function plateColor()
    {
        return $this->belongsTo(PlateColors::class, 'plate_color');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES (WAJIB BANGET BIAR CLEAN)
    |--------------------------------------------------------------------------
    */

    /**
     * Named `forOutlet` / `beltFresh`, not `outlet` / `fresh`: Eloquent resolves
     * a real method before a scope, so `outlet()` would hit the belongsTo
     * relation and `fresh()` would hit Model::fresh() — neither would filter.
     */
    public function scopeForOutlet($query, $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }

    public function scopeBeltFresh($query)
    {
        return $query->where('belt_status', 'fresh');
    }

    public function scopeWarning($query)
    {
        return $query->where('belt_status', 'warning');
    }

    public function scopeExpired($query)
    {
        return $query->where('belt_status', 'expired');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('belt_status', ['fresh', 'warning']);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSOR (BIAR AUTO SESUAI FRONTEND)
    |--------------------------------------------------------------------------
    */

    public function getTimeOnBeltAttribute()
    {
        return now()->diffInMinutes($this->produced_at);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER LOGIC
    |--------------------------------------------------------------------------
    */

    public function updateStatus()
    {
        $minutes = now()->diffInMinutes($this->produced_at);

        if ($minutes >= 60) {
            $this->belt_status = 'expired';
        } elseif ($minutes >= 45) {
            $this->belt_status = 'warning';
        } else {
            $this->belt_status = 'fresh';
        }

        return $this;
    }

    public function updateBeltStatus()
    {
        $now = now();

        if ($this->expires_at <= $now) {
            $this->belt_status = 'expired';
        } elseif ($this->expires_at <= $now->copy()->addMinutes(15)) {
            $this->belt_status = 'warning';
        } else {
            $this->belt_status = 'fresh';
        }

        return $this;
    }
}