<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProcessedRequest extends Model
{
    use HasUuid;

    protected $table = 'processed_requests';

    protected $fillable = [
        'request_id',
        'method',
        'path',
        'user_id',
        'response_status',
        'response_body',
    ];

    protected $casts = [
        'response_status' => 'integer',
    ];
}
