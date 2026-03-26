<?php 

namespace App\Models;
  
class ProductionPlan extends BaseModel
{ 
    protected $table = 'production_plans';
    
    protected $fillable = [
        'date',
        'time_slot',
        'outlet_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];
  
    public function items()
    {
        return $this->hasMany(ProductionPlanItem::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}