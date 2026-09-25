<?php

namespace App\Models;
 
class PlateColors extends BaseModel
{ 
    protected $table = 'plate_colors'; 

    protected $fillable = [
        'platename',
        // Warna tampil badge, '#RRGGBB'. Nullable: baris lama yang namanya tidak
        // dikenal peta warna lama tetap kosong dan jatuh ke warna cadangan.
        'color_hex',
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
