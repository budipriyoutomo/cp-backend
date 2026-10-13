<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseRequest;

/**
 * Pembatalan draft Sales Input oleh admin. Alasannya wajib — ia menghapus
 * pekerjaan operator, dan tersimpan di `sales_headers.deletion_reason`.
 */
class CancelSalesDraftRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan pembatalan'];
    }
}
