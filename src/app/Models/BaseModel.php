<?php

namespace App\Models;

use App\Traits\HasUserstamps;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BaseModel extends Model
{
    use SoftDeletes, HasUserstamps, HasUuid;

    /**
     * Jangan override boot() di sini.
     * Semua event creating/updating/deleting sudah ditangani oleh trait HasUuid & HasUserstamps
     */

    /**
     * Non-incrementing UUID sudah diatur di HasUuid
     */
}
