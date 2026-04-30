<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

class Menu extends BaseModel
{
    protected $table = 'menus'; 
 
    protected $fillable = [
        'code',
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

    protected $hidden = ['image'];
    protected $appends = ['image_url'];
    
    public function plateColor()
    {
        return $this->belongsTo(PlateColors::class, 'plate_color_id');
    }

    public function getImageUrlAttribute()
    {
        return $this->image
            ? Storage::disk('s3')->url($this->image)
            : null;
    }

}
