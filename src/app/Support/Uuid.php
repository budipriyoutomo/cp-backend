<?php

namespace App\Support;

/**
 * Semua primary key domain di sistem ini bertipe `uuid` di PostgreSQL (lihat
 * ../../../CLAUDE.md), dan `uuid` adalah tipe sungguhan: membandingkannya
 * dengan string sembarang bukan menghasilkan "tidak ketemu", melainkan
 *
 *   SQLSTATE[22P02]: invalid input syntax for type uuid: "abc"
 *
 * yaitu 500 dengan SQL bocor ke klien, bukan 404 atau 422. Nilainya datang dari
 * `?outlet_id=`, dari body, dan dari segmen URL — jadi apa pun bisa masuk.
 *
 * Aturan yang sama sebelumnya ditulis ulang di `OutletAccess` dan di
 * `ResolvesOutletBrand`. Dua salinan berarti dua tempat yang bisa melenceng,
 * sementara pola ini juga dibutuhkan sebagai batasan segmen route. Satu tempat
 * saja.
 */
final class Uuid
{
    /**
     * Tanpa jangkar `^...$` — dipakai apa adanya sebagai batasan segmen route
     * (`whereUuid`), dan dijangkarkan sendiri oleh `matches()`.
     */
    public const PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public static function matches(mixed $value): bool
    {
        return is_string($value)
            && (bool) preg_match('/^' . self::PATTERN . '$/', $value);
    }
}
