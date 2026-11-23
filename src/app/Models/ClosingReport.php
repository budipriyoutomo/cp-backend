<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClosingReport extends BaseModel
{
    protected $table = 'closing_reports';

    protected $fillable = [
        'outlet_id',
        'sales_id',
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

    public function sales(): BelongsTo
    {
        return $this->belongsTo(SalesHeader::class, 'sales_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ClosingReportEntry::class, 'closing_report_id');
    }

    /**
     * Pesan submit laporan ini ke BI (`message_id` = id laporan). Kiriman
     * ulang (`closing-report:replay`, `<id>.v3`) adalah baris lain dan tidak
     * menggeser status yang ditampilkan layar Closing Report.
     *
     * CAST, bukan whereColumn: di PostgreSQL `closing_report_id` bertipe uuid
     * dan `message_id` varchar — membandingkannya langsung adalah error.
     */
    public function outbox(): HasOne
    {
        return $this->hasOne(ClosingReportOutbox::class, 'closing_report_id')
            ->whereRaw('closing_report_outbox.message_id = CAST(closing_report_outbox.closing_report_id AS VARCHAR(64))');
    }

    public function outboxMessages(): HasMany
    {
        return $this->hasMany(ClosingReportOutbox::class, 'closing_report_id');
    }
}
