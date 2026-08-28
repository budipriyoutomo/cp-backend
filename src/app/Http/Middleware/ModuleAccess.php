<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batas modul, ditegakkan di server.
 *
 * `users.module_app` sudah lama menentukan halaman mana yang boleh dibuka —
 * tapi hanya di frontend, lewat `AuthGuard`. Server membawa daftarnya di klaim
 * JWT (`User::getJWTCustomClaims()`) dan tidak pernah membacanya, jadi siapa
 * pun yang memegang token bisa memanggil endpoint modul mana pun. Halaman yang
 * tidak dirender bukan endpoint yang tertutup.
 *
 * Sengaja terpisah dari `RoleMiddleware`, karena keduanya menjawab pertanyaan
 * berbeda: `role:` menjawab "boleh melakukan apa" (tulis master, hapus closing
 * report), `module:` menjawab "boleh sampai ke mana". Satu rute boleh memakai
 * keduanya, dan beberapa memang begitu.
 *
 * Tidak ada jalan pintas untuk `admin` di sini — beda dengan `OutletAccess`.
 * Kalau seorang admin hanya diberi modul `admin`, itu memang yang dimaksud
 * orang yang menyetelnya.
 */
class ModuleAccess
{
    public function handle(Request $request, Closure $next, ...$modules): Response
    {
        $user = auth()->user();

        // Tidak ada user berarti `auth:api` yang akan menolak. Menjawab 403 di
        // sini hanya menukar 401 yang benar dengan pesan yang salah.
        if (! $user) {
            return $next($request);
        }

        if (! array_intersect($this->modulesOf($user), $modules)) {
            // Amplop sama dengan RoleMiddleware dan BaseApiController::error().
            return response()->json([
                'status'  => false,
                'message' => 'Modul ini tidak termasuk akses Anda',
                'errors'  => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * `module_app` di-cast `array` oleh model, tapi baris lama bisa menyimpan
     * JSON mentah — sama seperti `role`. Dinormalkan supaya user yang sah tidak
     * terkunci oleh detail penyimpanan.
     *
     * `null` dan `[]` sama-sama berarti **tidak punya modul**, bukan punya
     * semuanya. `array_intersect` di atas gagal-tertutup untuk keduanya.
     *
     * @return array<int, string>
     */
    private function modulesOf($user): array
    {
        $modules = $user->module_app;

        if (is_array($modules)) {
            return $modules;
        }

        if (is_string($modules)) {
            $decoded = json_decode($modules, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
