<?php

namespace App\Models;

/**
 * Satu piring yang diubah oleh satu koreksi, beserta nilai sebelum diubah.
 */
class ProductionStatusCorrectionItem extends BaseModel
{
    protected $table = 'production_status_correction_items';

    protected $fillable = [
        'correction_id',
        'production_item_id',
        'menu_id',
        'plate_color',
        'from_status',
        'to_status',
        'old_sold_at',
        'old_wasted_at',
        'waste_record_id',
    ];

    protected $casts = [
        'old_sold_at'   => 'datetime',
        'old_wasted_at' => 'datetime',
    ];

    public function correction()
    {
        return $this->belongsTo(ProductionStatusCorrection::class, 'correction_id');
    }

    public function productionItem()
    {
        return $this->belongsTo(ProductionItem::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
}
