<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class SalesItem extends BaseModel
{
    protected $table = 'sales_items';

    protected $fillable = [
        'id',
        'sales_id',
        'plate_color_id',
        'pos_sold',
        'production_sold',
        'production_waste',
        'adjustment',
        'compensation',
        'selisih',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $casts = [
        'pos_sold' => 'integer',
        'production_sold' => 'integer',
        'production_waste' => 'integer',
        'adjustment' => 'integer',
        'compensation' => 'integer',
        'selisih' => 'integer',
    ];

    public function sales(): BelongsTo
    {
        return $this->belongsTo(SalesHeader::class, 'sales_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(SalesItemDetail::class, 'sales_item_id');
    }

    public function plateColor()
    {
        return $this->belongsTo(PlateColors::class, 'plate_color_id');
    }
}