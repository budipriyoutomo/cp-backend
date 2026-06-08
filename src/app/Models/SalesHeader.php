<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SalesItem> $items
 */
class SalesHeader extends BaseModel
{
    protected $table = 'sales_headers';

    protected $fillable = [
        'id',
        'outlet_id',
        'date',
        'status',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesItem::class, 'sales_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function closingReport()
    {
        return $this->hasOne(ClosingReport::class,'sales_id');
    }
}