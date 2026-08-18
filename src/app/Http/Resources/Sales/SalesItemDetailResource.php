<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\BaseResource;

class SalesItemDetailResource extends BaseResource
{
    // `menu_name` adalah nama menu yang disalin saat sales dibuat — menu bernama
    // angka ("2024") akan dikirim sebagai number tanpa daftar ini.
    protected array $textFields = ['menu_name'];

    public function toArray($request): array
    {
        return array_merge(
            $this->autoDetect(),
            $this->systemFields()
        );
    }
}