<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

class Brand extends BaseModel
{
    protected $table = 'brands';

    protected $fillable = [
        'code',
        'name',
        'description',
        'logo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = ['logo'];
    protected $appends = ['logo_url'];

    public function outlets()
    {
        return $this->hasMany(Outlet::class, 'brand_id');
    }

    public function menus()
    {
        return $this->hasMany(Menu::class, 'brand_id');
    }

    public function plateColors()
    {
        return $this->hasMany(PlateColors::class, 'brand_id');
    }

    public function getLogoUrlAttribute()
    {
        return $this->logo
            ? Storage::disk('s3')->url($this->logo)
            : null;
    }
}
