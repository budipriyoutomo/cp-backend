<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class BrandResource extends BaseResource
{
    // Sisanya otomatis lewat BaseResource.
    // Semuanya kolom varchar; `code` unik, jadi konversi yang menghapus nol di
    // depan bisa membuat dua brand terlihat sama di sisi pemakai.
    protected array $textFields = ['code', 'name', 'description', 'logo'];
}
