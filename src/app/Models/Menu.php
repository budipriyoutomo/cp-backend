<?php

namespace App\Models;
 
class Menu extends BaseModel
{
    protected $table = 'menus'; 
 
    protected $fillable = [
        'menuname',
        'description',
        'image',
        'price',
        'shelf_life',
        'plate_color_id',
        'is_active'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];
    
    public function platecolor()
    {
        return $this->belongsTo(PlateColors::class, 'plate_color_id');
    }
}
