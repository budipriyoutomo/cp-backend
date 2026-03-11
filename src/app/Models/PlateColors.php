<?php

namespace App\Models;
 
class PlateColors extends BaseModel
{ 
    protected $table = 'plate_colors'; 

    protected $fillable = [
        'platename',
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
}
