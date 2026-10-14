<?php

namespace App\Models;

class WasteReason extends BaseModel
{
    public const AUTO_WASTE = 'Auto-waste';
    public const IMPORT_BACKDATE = 'Import backdate';

    /**
     * Reason yang dipakai kode, bukan dipilih operator. Tidak boleh dihapus
     * atau di-rename, dan tidak ditawarkan di dropdown dapur.
     *
     * @var array<string, string> nama => deskripsi
     */
    public const SYSTEM_REASONS = [
        self::AUTO_WASTE      => 'Piring tidak diselesaikan pada hari produksi (production:close-stale)',
        self::IMPORT_BACKDATE => 'Waste dari impor produksi hari lalu',
    ];

    protected $table = 'waste_reasons';

    protected $fillable = [
        'reason_name',
        'description',
        'waste_category_id',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(WasteCategory::class, 'waste_category_id');
    }
}
