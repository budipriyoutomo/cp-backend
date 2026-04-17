<?php

namespace App\Models;
 
class POSData extends BaseModel
{ 
    protected $table = 'posdata';
    protected $fillable = [
        'id',
        'plate_color_id',
        'outlet_id',
        'date',
        'sold'
    ];

    public function plateColor()
    {
        return $this->belongsTo(PlateColors::class, 'plate_color_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
