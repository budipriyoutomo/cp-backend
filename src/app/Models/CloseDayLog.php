<?php

namespace App\Models;

/**
 * Satu penutupan massal piring sisa: tombol Tutup Hari
 * (ProductionItemService::closeDaySold()) atau auto-waste
 * (ProductionItemService::autoWasteCarryOver()).
 */
class CloseDayLog extends BaseModel
{
    public const ACTION_CLOSE_DAY  = 'close_day';
    public const ACTION_AUTO_WASTE = 'auto_waste';

    public const ACTIONS = [self::ACTION_CLOSE_DAY, self::ACTION_AUTO_WASTE];

    public const TRIGGER_BUTTON       = 'button';
    public const TRIGGER_SCHEDULER    = 'scheduler';
    public const TRIGGER_COMMAND      = 'command';
    public const TRIGGER_GET_DATA_POS = 'get_data_pos';

    protected $table = 'close_day_logs';

    protected $fillable = [
        'outlet_id',
        'action',
        'trigger',
        'business_date',
        'closed_count',
        'executed_at',
        'client_request_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'business_date' => 'date',
        'closed_count'  => 'integer',
        'executed_at'   => 'datetime',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    // Sengaja tanpa relasi ke User, sama seperti ProductionStatusCorrection:
    // `created_by` bertipe char sementara `users.id` bigint.
}
