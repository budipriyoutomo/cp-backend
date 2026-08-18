<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class OutletResource extends BaseResource
{
    // Sisanya otomatis lewat BaseResource.
    //
    // `code` yang paling rawan: kode outlet berupa angka ("01", "2") bukan hal
    // aneh, dan `POSService` mencocokkan payload POS ke `outlets.code` — kalau
    // "01" berubah jadi 1, pencocokannya meleset dan angka penjualan masuk ke
    // outlet yang salah.
    protected array $textFields = ['code', 'name', 'brand', 'address'];
}
