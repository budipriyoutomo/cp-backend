<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesItemDetail extends BaseModel
{
    protected $table = 'sales_item_details';

    protected $fillable = [
        'id',
        'sales_item_id',
        'menu_id',
        'menu_name',
        'total_produced',
        'total_sold',
        'total_wasted',
        'adjustment',
        'compensation',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $casts = [
        'total_produced' => 'integer',
        'total_sold' => 'integer',
        'total_wasted' => 'integer',
        'adjustment' => 'integer',
        'compensation' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesItem::class, 'sales_item_id');
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}