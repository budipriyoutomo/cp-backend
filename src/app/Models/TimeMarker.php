<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penanda waktu — lingkaran warna yang menempel ke piring supaya staf tahu
 * piring itu dibuat di slot yang mana.
 *
 * Warna teks di atasnya sengaja TIDAK disimpan; ia dihitung dari kecerahan
 * `color_hex` di sisi klien. Dua nilai yang menggambarkan hal sama selalu
 * berakhir menyimpang.
 */
class TimeMarker extends BaseModel
{
    protected $table = 'time_markers';

    protected $fillable = [
        'brand_id',
        'label',
        'color_hex',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(TimeSlot::class, 'time_marker_id');
    }
}
