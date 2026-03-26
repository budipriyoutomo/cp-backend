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

    public function scopeOutlet($query, $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }

    public function scopeFresh($query)
    {
        return $query->where('status', 'fresh');
    }

    public function scopeWarning($query)
    {
        return $query->where('status', 'warning');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['fresh', 'warning']);
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
            $this->status = 'expired';
        } elseif ($minutes >= 45) {
            $this->status = 'warning';
        } else {
            $this->status = 'fresh';
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