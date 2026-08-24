<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * Dipakai preview maupun commit — keduanya menerima berkas yang sama, dan
 * commit hanya menambah satu tombol pengaman.
 *
 * `mimes:csv,txt` sengaja longgar: berkas CSV dari Excel sering dilaporkan
 * browser sebagai `text/plain` atau `application/vnd.ms-excel`, dan menolaknya
 * di sini berarti operator melihat "format tidak didukung" untuk berkas yang
 * isinya benar. Bentuk isinya divalidasi baris per baris oleh service.
 */
class ProductionBackdateImportRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'file'           => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'outletId'       => ['required', 'uuid', 'exists:outlets,id'],
            'allowDuplicate' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file'     => 'berkas CSV',
            'outletId' => 'outlet',
        ];
    }
}
