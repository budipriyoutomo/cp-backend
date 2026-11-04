<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;
use App\Models\CloseDayLog;

/**
 * Satu penutupan massal: Tutup Hari atau auto-waste. `executedBy` null pada
 * auto-waste dari scheduler/artisan — tidak ada user yang login.
 *
 * @mixin CloseDayLog
 */
class CloseDayLogResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'outletId'        => $this->outlet_id,
            'outletCode'      => $this->outlet?->code,
            'outletName'      => $this->outlet?->name,
            'action'          => $this->action,
            'trigger'         => $this->trigger,
            'businessDate'    => $this->business_date?->format('Y-m-d'),
            'closedCount'     => (int) $this->closed_count,
            'executedAt'      => $this->executed_at?->toIso8601String(),
            'clientRequestId' => $this->client_request_id,
            'ipAddress'       => $this->ip_address,
            'userAgent'       => $this->user_agent,
            'executedBy'      => $this->created_by !== null ? trim((string) $this->created_by) : null,
            'executedByName'  => $this->creator_name,
        ];
    }
}
