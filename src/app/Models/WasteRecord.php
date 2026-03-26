<?php 

namespace App\Models;
 
class WasteRecord extends BaseModel
{
    protected $table = 'waste_records';
    
    protected $fillable = [
        'menu_id',
        'outlet_id',
        'plate_color',
        'quantity',
        'reason',
        'recorded_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATION
    |--------------------------------------------------------------------------
    */

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}