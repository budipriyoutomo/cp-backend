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
        'brand_id',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * SENGAJA tidak dinamai brand().
     *
     * Kolom teks `brand` masih ada di tabel ini. Relasi bernama `brand()` akan
     * bertabrakan dengannya: `$outlet->brand` tetap mengembalikan string (atribut
     * menang atas relasi), tapi `toArray()` menggabungkan relasi DI ATAS atribut
     * — jadi begitu ada `with('brand')` di suatu tempat, field `brand` pada
     * respons outlet diam-diam berubah dari string jadi objek.
     *
     * Namanya kembali jadi brand() bersamaan dengan migration yang men-drop
     * kolom teks itu.
     */
    public function brandMaster()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}
