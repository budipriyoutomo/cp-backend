<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class TimeMarkerResource extends BaseResource
{
    // `label` boleh saja diisi angka ("1", "2", "007") — sebagian dapur memberi
    // nomor, bukan nama warna. Tanpa ini `formatValue()` mengirimnya sebagai
    // number dan "007" berubah jadi 7.
    //
    // `color_hex` tidak pernah lolos tebakan angka, tapi didaftarkan supaya
    // niatnya terbaca: ini kode warna, bukan nilai.
    protected array $textFields = ['label', 'color_hex'];
}
