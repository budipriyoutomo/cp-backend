<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class PlateColorResource extends BaseResource
{
    // Sisanya otomatis lewat BaseResource.
    //
    // `platename` dikecualikan: warna bernama angka akan dikirim sebagai number,
    // dan `plateColorName` dipakai dengan `.toLowerCase()` di layar menu maupun
    // di `PlateColorBadge`. `price` sengaja TIDAK di sini — ia memang harus
    // jadi angka.
    protected array $textFields = ['platename', 'description'];
}
