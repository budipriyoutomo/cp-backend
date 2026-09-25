<?php

namespace App\Services\Master;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Penyaringan `?outlet_id=` untuk setelan waktu.
 *
 * Sengaja BUKAN `ResolvesOutletBrand::scopeToBrand()`. Aturan di sana ikut
 * memasukkan baris ber-`brand_id` NULL supaya terlihat oleh semua outlet —
 * kelonggaran transisi untuk menu dan plate color yang belum di-backfill.
 *
 * Di sini kelonggaran itu tidak punya arti dan justru merusak:
 *
 * - `time_slots.brand_id` dan `time_markers.brand_id` NOT NULL, jadi tidak ada
 *   baris tanpa brand yang perlu ditampung.
 * - Outlet yang brand-nya belum diisi akan menerima slot SEMUA brand kalau
 *   penyaringannya dilewati. Layar planning menampilkan jam kembar berkali-kali
 *   dan satu jam produksi cocok dengan banyak slot sekaligus.
 *
 * Jadi outlet tanpa brand menerima daftar kosong. Layar menanganinya sebagai
 * "brand ini belum punya setelan" dan mengarahkan ke halaman setelan — gagal
 * terang-terangan, bukan diam-diam salah.
 *
 * Resolusi outlet → brand tetap memakai `brandIdForOutlet()` milik
 * ResolvesOutletBrand, termasuk penjagaan bentuk uuid dan 404-nya. Yang beda
 * hanya apa yang dilakukan terhadap hasilnya.
 */
trait ScopesToOutletBrandStrictly
{
    protected function scopeToOutletBrand(Builder $query, Request $request): Builder
    {
        $outletId = $request->query('outlet_id');

        if (! $outletId) {
            return $query;
        }

        // brandIdForOutlet() melempar 404 untuk outlet tak dikenal maupun uuid
        // rusak. NULL berarti outletnya ada tapi belum punya brand — dan
        // `where('brand_id', null)` menghasilkan `brand_id IS NULL`, yang pada
        // kolom NOT NULL berarti nol baris. Itu memang yang diinginkan.
        return $query->where('brand_id', $this->brandIdForOutlet($outletId));
    }
}
