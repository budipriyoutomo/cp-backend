<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Satu-satunya tempat aturan "user ini boleh outlet apa".
 *
 * Dulu aturan ini hidup di dalam middleware `OutletAccess`, yang hanya bisa
 * memeriksa outlet yang disebut di request (`outletId` / `outlet_id`). Data
 * yang dicari lewat id — `GET /sales/{id}`, `DELETE /closing-reports/{id}`, dst.
 * — tidak membawa parameter outlet, jadi middleware meloloskannya. Service
 * yang mengambil record seperti itu harus bisa menanyakan aturan yang sama
 * setelah outlet pemiliknya diketahui. Menyalin logikanya berarti dua aturan
 * yang suatu saat berbeda; karena itu keduanya memanggil kelas ini.
 *
 * Aturannya:
 * - role `admin` boleh semua outlet (sama seperti OutletProvider di frontend);
 * - user lain hanya outlet yang kodenya ada di `users.outlet`, dibandingkan
 *   setelah `strtolower(trim())` di kedua sisi;
 * - `users.outlet` kosong/null berarti tidak boleh outlet apa pun.
 *
 * Kelas ini stateless; ambil lewat container (`app(OutletGate::class)`) atau
 * injeksi konstruktor.
 */
final class OutletGate
{
    /** Pesan yang sama dengan penolakan middleware `outlet.access`. */
    public const DENIED_MESSAGE = 'Outlet ini tidak termasuk akses Anda';

    public function isAdmin(User $user): bool
    {
        return in_array('admin', $this->rolesOf($user), true);
    }

    /**
     * Kode outlet yang boleh diakses user, sudah dinormalkan.
     *
     * Baris lama bisa menyimpan array yang di-encode dua kali, sehingga cast
     * `array` di model menghasilkan string JSON — bentuk itu ikut dibaca.
     *
     * @return array<int, string>
     */
    public function allowedCodes(User $user): array
    {
        $outlets = $user->outlet;

        if (is_string($outlets)) {
            $decoded = json_decode($outlets, true);
            $outlets = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($outlets)) {
            return [];
        }

        return array_values(array_map(fn ($code) => $this->normalize((string) $code), $outlets));
    }

    /**
     * Menerima model Outlet atau id-nya (uuid).
     *
     * Gagal-tertutup untuk non-admin: id yang bentuknya salah atau tidak
     * menunjuk outlet mana pun dijawab `false`, bukan dianggap boleh. Bentuk
     * diperiksa dulu karena `outlets.id` bertipe uuid di PostgreSQL —
     * membandingkannya dengan string sembarang melempar 22P02 (500).
     */
    public function canAccess(User $user, Outlet|string $outlet): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (is_string($outlet)) {
            // Beberapa kolom `outlet_id` lama terbaca dengan spasi di ujung
            // (lihat SalesController::destroy yang men-trim-nya).
            $outlet = trim($outlet);
            $outlet = Uuid::matches($outlet) ? Outlet::find($outlet) : null;

            if (! $outlet) {
                return false;
            }
        }

        return in_array($this->normalize($outlet->code), $this->allowedCodes($user), true);
    }

    /**
     * Lempar 403 dengan envelope standar `{ status:false, message, errors:null }`
     * — dirender oleh `BusinessRuleException::render()`, sama persis dengan
     * respons middleware `outlet.access`.
     *
     * @throws BusinessRuleException
     */
    public function authorize(User $user, Outlet|string $outlet): void
    {
        if (! $this->canAccess($user, $outlet)) {
            throw new BusinessRuleException(self::DENIED_MESSAGE, 403);
        }
    }

    /**
     * Untuk endpoint baca berbasis id: record milik outlet lain dijawab persis
     * seperti id yang tidak ada (`ModelNotFoundException` → 404), supaya
     * keberadaan id outlet lain tidak bocor lewat beda 403 vs 404.
     *
     * Mutasi memakai `authorize()` (403) — user yang memegang id dari layar
     * yang sah perlu tahu kenapa aksinya ditolak.
     *
     * @throws ModelNotFoundException
     */
    public function ensureVisible(User $user, Model $record, string $outletColumn = 'outlet_id'): void
    {
        if (! $this->canAccess($user, (string) $record->getAttribute($outletColumn))) {
            throw (new ModelNotFoundException())->setModel($record::class, [$record->getKey()]);
        }
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * `users.role` sebenarnya kolom string, tapi baris lama boleh berbentuk
     * array. Aturannya sama dengan RoleMiddleware::rolesOf().
     *
     * @return array<int, mixed>
     */
    private function rolesOf(User $user): array
    {
        $role = $user->role;

        if (is_array($role)) {
            return $role;
        }

        if (is_string($role)) {
            $decoded = json_decode($role, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [$role];
    }
}
