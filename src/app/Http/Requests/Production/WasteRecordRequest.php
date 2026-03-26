<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

class WasteRecordRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'itemId' => ['nullable', 'uuid', 'exists:production_items,id'],
            'itemIds' => ['nullable', 'array'],
            'itemIds.*' => ['uuid', 'exists:production_items,id'],

            'reason' => ['required', 'string'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$this->itemId && !$this->itemIds) {
                $validator->errors()->add('itemId', 'itemId atau itemIds wajib diisi');
            }
        });
    }

    public function getItemIds(): array
    {
        if ($this->itemIds) {
            return $this->itemIds;
        }

        return [$this->itemId];
    }
}