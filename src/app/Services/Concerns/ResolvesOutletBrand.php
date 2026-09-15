<?php

namespace App\Services\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\Outlet;
use App\Support\Uuid;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu outlet = satu brand, jadi outlet adalah satu-satunya hal yang perlu
 * diketahui pemanggil. Aturan penyaringannya dikumpulkan di sini supaya
 * master data, dashboard, produksi dan planning tidak masing-masing menulis
 * versinya sendiri lalu menyimpang pelan-pelan.
 *
 * Aturannya sama persis dengan yang dipakai POSService (Fase 3): baris milik
 * brand outlet ikut, baris yang `brand_id`-nya masih NULL juga ikut, baris
 * milik brand lain tidak. Bagian NULL itu kelonggaran transisi — migrasi Fase 2
 * sengaja meninggalkan `brand_id` kosong kalau brand-nya lebih dari satu, dan
 * tanpa kelonggaran ini dapur akan melihat layar kosong.
 */
trait ResolvesOutletBrand
{
    protected function brandIdForOutlet(string $outletId): ?string
    {
        // Bentuknya dicek dulu. `outlets.id` bertipe uuid di PostgreSQL, dan
        // membandingkannya dengan string sembarang melempar "invalid input
        // syntax for type uuid" — 500, bukan 404. Nilai ini datang dari query
        // string `?outlet_id=`, jadi apa pun bisa masuk.
        if (! Uuid::matches($outletId)) {
            throw new BusinessRuleException("Outlet tidak ditemukan: {$outletId}", 404);
        }

        $outlet = Outlet::find($outletId);

        if (!$outlet) {
            throw new BusinessRuleException("Outlet tidak ditemukan: {$outletId}", 404);
        }

        return $outlet->brand_id;
    }

    /**
     * Outlet tanpa brand tidak menyaring apa pun. Menyaringnya jadi
     * "brand_id IS NULL" akan menyembunyikan seluruh master dari outlet yang
     * brand-nya belum diisi — gagal diam-diam, persis yang mau dihindari.
     */
    protected function scopeToBrand(Builder $query, ?string $brandId): Builder
    {
        if ($brandId === null) {
            return $query;
        }

        return $query->where(function ($q) use ($brandId) {
            $q->where('brand_id', $brandId)->orWhereNull('brand_id');
        });
    }
}
