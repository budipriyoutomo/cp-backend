<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

class UploadWastePhotosRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'max:5120'],
        ];
    }
}
