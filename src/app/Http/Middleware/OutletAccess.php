<?php

namespace App\Http\Middleware;

use App\Models\Outlet;
use App\Support\Uuid;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outlet adalah batas data di sistem ini, tapi sampai sekarang batas itu hanya
 * dijaga frontend: OutletProvider menyaring daftar outlet menurut `users.outlet`
 * sebelum menaruhnya di OutletSelector. Server tidak pernah memeriksa ulang,
 * jadi siapa pun yang memegang token bisa mengirim `?outlet_id=` milik outlet
 * lain dan membaca master data, angka produksi, dan harga brand lain.
 *
 * Middleware ini memindahkan aturan itu ke server. Ia sengaja tidak menebak:
 * request yang tidak menyebut outlet sama sekali dibiarkan lewat — layar master
 * admin memang lintas outlet, dan yang menjaganya adalah `role:`.
 */
class OutletAccess
{
    /** Nama parameter outlet yang dipakai di seluruh API. */
    private const KEYS = ['outletId', 'outlet_id'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Tidak ada user berarti `auth:api` yang akan menolak. Menjawab 403 di
        // sini hanya menukar 401 yang benar dengan pesan yang salah.
        if (! $user) {
            return $next($request);
        }

        // Admin melihat semua outlet, sama seperti di OutletProvider frontend.
        if (in_array('admin', $this->rolesOf($user), true)) {
            return $next($request);
        }

        $outletId = $this->outletIdFrom($request);

        if ($outletId === null || ! $this->isUuid($outletId)) {
            return $next($request);
        }

        $outlet = Outlet::find($outletId);

        // Outlet tak dikenal bukan urusan izin. Biarkan service menjawab 404-nya
        // supaya pesan error tidak berubah jadi 403 yang menyesatkan.
        if (! $outlet) {
            return $next($request);
        }

        if (! in_array($this->normalize($outlet->code), $this->allowedCodes($user), true)) {
            // Envelope sama dengan RoleMiddleware dan BaseApiController::error().
            return response()->json([
                'status'  => false,
                'message' => 'Outlet ini tidak termasuk akses Anda',
                'errors'  => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Query string dulu, baru body: `?outlet_id=` dipakai master data, sementara
     * mutasi mengirim `outletId` di JSON.
     */
    private function outletIdFrom(Request $request): ?string
    {
        foreach (self::KEYS as $key) {
            $value = $request->query($key) ?? $request->input($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * `outlets.id` bertipe uuid di PostgreSQL. Membandingkannya dengan string
     * sembarang melempar "invalid input syntax for type uuid" — 500, bukan 403.
     * Nilainya datang dari request, jadi apa pun bisa masuk.
     *
     * Sama seperti outlet yang tidak ditemukan: bentuk yang salah dilewatkan,
     * biar service yang menjawabnya.
     */
    private function isUuid(string $value): bool
    {
        return Uuid::matches($value);
    }

    /**
     * Kode outlet dibandingkan setelah dinormalkan, persis seperti yang
     * dilakukan OutletProvider di frontend — kalau kedua sisi memakai aturan
     * berbeda, dapur akan melihat outlet yang tidak bisa dibukanya.
     *
     * @return array<int, string>
     */
    private function allowedCodes($user): array
    {
        $outlets = $user->outlet;

        if (is_string($outlets)) {
            $decoded = json_decode($outlets, true);
            $outlets = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($outlets)) {
            return [];
        }

        return array_map(fn ($code) => $this->normalize((string) $code), $outlets);
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * `users.role` sebenarnya kolom string, tapi baris lama boleh berbentuk
     * array. Aturannya sama dengan RoleMiddleware::rolesOf().
     *
     * @return array<int, string>
     */
    private function rolesOf($user): array
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
