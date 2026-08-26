<?php

namespace App\Http\Resources\Production;

use App\Http\Resources\BaseResource;

/**
 * Satu batch produksi, bukan satu piring.
 *
 * `produce()` membuat N baris terpisah dalam satu transaksi dengan `$now` yang
 * sama, jadi piring dalam satu batch identik: menu sama, `produced_at` sama,
 * `expires_at` sama. Menggabungkannya tidak membuang informasi apa pun —
 * countdown dan warna piringnya memang satu nilai untuk seluruh group.
 *
 * Yang dihemat bukan query melainkan JSON. Belt 1000 piring dulu terkirim
 * sebagai 1000 objek berisi 20 field tiap 30 detik per tablet; setelah
 * digabung tinggal beberapa puluh group. `systemFields()` sengaja TIDAK ikut:
 * `created_by`/`deleted_at` dan kawan-kawannya tidak pernah dibaca layar
 * conveyor, dan enam field mati dikali seribu baris itulah sebagian besar
 * payload lamanya.
 *
 * `itemIds` tetap dibawa supaya mutasi tetap berbasis id — `mark-waste` dan
 * `/waste` sudah menerima array id, jadi tidak ada endpoint tulis yang perlu
 * berubah, dan dua tablet yang membuang dari group yang sama tidak bisa
 * membuang piring yang sama dua kali.
 */
class ProductionItemGroupResource extends BaseResource
{
    public function toArray($request): array
    {
        $group = $this->resource;

        return [
            'groupKey'       => $group['group_key'],
            'menuId'         => $group['menu_id'],
            'menuName'       => $group['menu_name'],
            'plateColor'     => $group['plate_color'],
            'plateColorName' => $group['plate_color_name'],
            'producedAt'     => $group['produced_at'],
            'expiresAt'      => $group['expires_at'],
            'beltStatus'     => $group['belt_status'],
            'quantity'       => $group['quantity'],
            'itemIds'        => $group['item_ids'],
        ];
    }
}
