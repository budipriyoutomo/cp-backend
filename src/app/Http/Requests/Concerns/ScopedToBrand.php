<?php

namespace App\Http\Requests\Concerns;

use App\Models\Brand;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Dua aturan yang berlaku untuk setiap master data yang menempel ke brand.
 */
trait ScopedToBrand
{
    /**
     * Wajib, tapi hanya kalau brand-nya memang ada.
     *
     * `required` polos akan mengunci instalasi yang belum punya brand sama
     * sekali — BrandSeeder melewati dirinya sendiri kalau BOOTSTRAP_BRANDS
     * kosong, jadi basis data baru sah-sah saja belum punya satu pun brand, dan
     * master menu / plate color tetap harus bisa diisi.
     *
     * Begitu ada brand, menebak jadi berbahaya dan nilainya wajib disebut —
     * kecuali brand-nya cuma satu, karena fillSoleBrand() sudah mengisinya.
     */
    protected function brandIdRules(): array
    {
        return [Rule::requiredIf(fn () => Brand::query()->exists()), 'nullable', 'uuid', 'exists:brands,id'];
    }

    /**
     * Kalau di basis data hanya ada SATU brand, `brand_id` diisi sendiri.
     *
     * Gunanya bukan kenyamanan, tapi kompatibilitas: PWA menyimpan bundel lama
     * di service worker, jadi selalu ada tablet yang masih mengirim payload
     * tanpa `brand_id` beberapa saat setelah backend naik. Selama brand cuma
     * satu, tidak ada yang bisa salah dipilih.
     *
     * Begitu brand kedua dibuat, penebakan berhenti dan brandIdRules() menolak
     * payload yang tidak menyebut brand — di titik itu menebak berarti
     * menempelkan harga brand A ke piring brand B.
     */
    protected function fillSoleBrand(): void
    {
        if (filled($this->input('brand_id'))) {
            return;
        }

        // limit(2) — yang perlu diketahui hanya "tepat satu atau bukan".
        $brands = Brand::query()->limit(2)->pluck('id');

        if ($brands->count() === 1) {
            $this->merge(['brand_id' => $brands->first()]);
        }
    }

    /**
     * Keunikan berlaku atas APA YANG TERLIHAT BERSAMA, bukan sekadar di dalam
     * satu brand.
     *
     * Dua brand memang boleh punya warna "Merah" dan menu "Salmon Nigiri"
     * masing-masing — itu inti dari keputusan per-brand.
     *
     * Tapi baris ber-`brand_id` NULL terlihat oleh SEMUA outlet (aturan
     * transisi yang sama dipakai POSService dan penyaringan master). Jadi kalau
     * pengecekannya hanya "di dalam brand", satu dapur bisa melihat dua "Merah"
     * sekaligus: satu miliknya, satu yang belum bertuan. Karena itu:
     *
     * - brand diisi  → bentrok kalau namanya sudah ada di brand itu ATAU di
     *   baris tanpa brand
     * - brand kosong → bentrok dengan apa pun, karena baris ini akan muncul di
     *   semua outlet
     *
     * Aturan ini yang mendorong operator menetapkan brand pada baris lama,
     * bukan menumpuk duplikat di atasnya.
     *
     * Baris soft-deleted dikecualikan: menghapus lalu membuat lagi dengan nama
     * yang sama adalah operasi yang sah.
     */
    protected function uniqueWithinBrand(string $table, string $column, ?string $ignoreId = null): Unique
    {
        $brandId = $this->input('brand_id');

        $rule = Rule::unique($table, $column)->where(function ($query) use ($brandId) {
            $query->whereNull('deleted_at');

            if ($brandId !== null) {
                $query->where(fn ($q) => $q->where('brand_id', $brandId)->orWhereNull('brand_id'));
            }

            return $query;
        });

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }
}
