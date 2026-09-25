<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu slot produksi milik satu brand.
 *
 * `start_time`/`end_time` sengaja dibiarkan string `HH:MM:SS`, bukan di-cast
 * jadi datetime: yang disimpan adalah jam dinding, bukan satu titik waktu.
 * Meng-cast-nya akan menempelkan tanggal hari ini dan zona waktu aplikasi ke
 * nilai yang tidak punya keduanya.
 */
class TimeSlot extends BaseModel
{
    protected $table = 'time_slots';

    protected $fillable = [
        'brand_id',
        'start_time',
        'end_time',
        'time_marker_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    protected $appends = ['label'];

    /**
     * Label kanonis sebuah slot: `"10:00-10:30"`.
     *
     * Inilah bentuk yang tersimpan di `production_plans.time_slot`, dan satu-
     * satunya tempat bentuk itu dibuat. Kalau ada dua tempat yang menyusunnya,
     * cukup satu yang menulis detik atau spasi untuk membuat plan tidak pernah
     * cocok dengan masternya lagi.
     */
    public function getLabelAttribute(): string
    {
        return self::formatLabel($this->start_time, $this->end_time);
    }

    public static function formatLabel(?string $start, ?string $end): string
    {
        return substr((string) $start, 0, 5) . '-' . substr((string) $end, 0, 5);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(TimeMarker::class, 'time_marker_id');
    }
}
