<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class ProductionExpiredChangeRequest extends BaseRequest
{  
     protected function rulesForUpdate(): array
    {
        return [
                'status' => 'required|in:sold,waste',
                'notes'  => 'required|string|max:255',
            ];
    } 
}