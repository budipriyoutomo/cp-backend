<?php

namespace App\Models;
 
class Outlet extends BaseModel
{
  
    protected $table = 'outlets';

    protected $fillable = [
        'id',
        'code',
        'name',
        'brand',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
 
    
}
