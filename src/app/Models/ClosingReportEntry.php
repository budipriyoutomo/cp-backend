<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClosingReportEntry extends BaseModel
{
    protected $table = 'closing_report_entries';

    protected $fillable = [
        'closing_report_id',
        'plate_color_id',
        'produced',
        'sold',
        'waste',
        'pos_sold',
        'adjustment',
        'compensation',
        'compensation_reason',
        'selisih',
    ];

    protected $casts = [
        'produced' => 'integer',
        'sold' => 'integer',
        'waste' => 'integer',
        'pos_sold' => 'integer',
        'adjustment' => 'integer',
        'compensation' => 'integer',
        'selisih' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ClosingReport::class, 'closing_report_id');
    }

    public function plateColor(): BelongsTo
    {
        return $this->belongsTo(PlateColors::class, 'plate_color_id');
    }
}
