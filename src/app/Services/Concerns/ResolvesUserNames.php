<?php

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Nama user dari kolom userstamp untuk layar riwayat/audit.
 *
 * Kolom userstamp bertipe char sementara `users.id` bigint, jadi tidak ada
 * relasi Eloquent yang bisa dipakai. Dikumpulkan di sini supaya riwayat
 * koreksi dan log Tutup Hari tidak menulis versinya masing-masing.
 */
trait ResolvesUserNames
{
    /**
     * Mengisi atribut `creator_name` dari `created_by` tiap baris.
     */
    protected function attachCreatorNames(Collection $rows): void
    {
        $names = $this->userNames($rows->pluck('created_by'));

        foreach ($rows as $row) {
            $row->setAttribute(
                'creator_name',
                $names[(int) trim((string) $row->created_by)] ?? null
            );
        }
    }

    /**
     * Id yang bukan angka dibuang sebelum menyentuh query.
     *
     * @return Collection<int, string> id => name
     */
    protected function userNames(Collection $ids): Collection
    {
        $ids = $ids
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $ids->all())->pluck('name', 'id');
    }
}
