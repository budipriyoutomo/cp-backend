<?php 

namespace App\Models;
 
class ProductionPlanItem extends BaseModel
{
    protected $table = 'production_plan_items';
    protected $fillable = [
        'production_plan_id',
        'plate_color',
        'qty',
    ];
 

    public function plan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }
}