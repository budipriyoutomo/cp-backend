<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * Unduh template. Hanya butuh outlet — outletlah yang menentukan brand, dan
 * brand yang menentukan menu mana yang masuk ke templatenya.
 */
class ProductionBackdateTemplateRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'outletId' => ['required', 'uuid', 'exists:outlets,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'outletId' => 'outlet',
        ];
    }
}
