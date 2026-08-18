<?php

namespace App\Models;
 
class PlateColors extends BaseModel
{ 
    protected $table = 'plate_colors'; 

    protected $fillable = [
        'platename',
        'brand_id',
        'price',
        'description',
        'target_foodcost',
        'is_active'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'target_foodcost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}
