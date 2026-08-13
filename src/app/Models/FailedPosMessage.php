<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A POS message the consumer could not process. Kept so it can be replayed
 * with `pos:replay-failed` once the underlying master data is fixed.
 *
 * Plain Model, not BaseModel: this is an operational log, not domain data —
 * no soft deletes and no userstamps (the consumer runs without a user).
 */
class FailedPosMessage extends Model
{
    use HasUuid;

    protected $table = 'failed_pos_messages';

    protected $fillable = [
        'payload',
        'error',
        'attempts',
        'resolved_at',
    ];

    protected $casts = [
        'attempts'    => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * The `data` block the consumer would have handed to POSService, or null if
     * the body was not usable JSON in the first place.
     */
    public function eventData(): ?array
    {
        $decoded = json_decode((string) $this->payload, true);

        return is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : null;
    }
}
