<?php

namespace App\Models;

class WasteReason extends BaseModel
{ 
    protected $table = 'waste_reasons'; 

    protected $fillable = [
        'reason_name', 
        'description', 
        'is_active'
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
