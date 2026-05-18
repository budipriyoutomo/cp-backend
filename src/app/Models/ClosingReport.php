<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClosingReport extends BaseModel
{
    protected $table = 'closing_reports';

    protected $fillable = [
        'outlet_id',
        'date',
        'status',
        'kitchen_leader',
        'operation_leader',
        'waste_photo_urls',
        'notes',
        'submitted_at',
        'submitted_by',
    ];

    protected $casts = [
        'date' => 'date',
        'waste_photo_urls' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ClosingReportEntry::class, 'closing_report_id');
    }
}
