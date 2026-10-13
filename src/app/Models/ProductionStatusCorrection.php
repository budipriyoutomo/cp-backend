<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu aksi Koreksi Status Produksi. Lihat ProductionStatusCorrectionService.
 */
class ProductionStatusCorrection extends BaseModel
{
    protected $table = 'production_status_corrections';

    protected $fillable = [
        'outlet_id',
        'production_date',
        'reason',
        'waste_reason_id',
        'total_to_waste',
        'total_to_sold',
    ];

    protected $casts = [
        'production_date' => 'date',
        'total_to_waste'  => 'integer',
        'total_to_sold'   => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProductionStatusCorrectionItem::class, 'correction_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function wasteReason()
    {
        return $this->belongsTo(WasteReason::class);
    }

    // Sengaja tanpa relasi ke User: `created_by` bertipe char sementara
    // `users.id` bigint. Nama pembuat diresolusi service lewat
    // ProductionStatusCorrectionService::attachCreatorNames().
}
