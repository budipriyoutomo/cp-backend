<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\BaseRequest;

/**
 * Dipakai preview maupun commit — keduanya menerima berkas yang sama, dan
 * commit hanya menambah satu tombol pengaman.
 *
 * Daftar `mimes` sengaja longgar: berkas CSV dari Excel sering dilaporkan
 * browser sebagai `text/plain` atau `application/vnd.ms-excel`, dan menolaknya
 * di sini berarti operator melihat "format tidak didukung" untuk berkas yang
 * isinya benar. Bentuk isinya divalidasi baris per baris oleh service.
 *
 * `xlsx` ikut sejak template yang diunduh berbentuk workbook: menyuruh operator
 * menyimpan ulang jadi CSV hanya memindahkan satu langkah yang gampang keliru
 * (sheet yang tersimpan bukan sheet datanya) ke tangan manusia.
 */
class ProductionBackdateImportRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'file'           => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048'],
            'outletId'       => ['required', 'uuid', 'exists:outlets,id'],
            'allowDuplicate' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file'     => 'berkas template',
            'outletId' => 'outlet',
        ];
    }
}
