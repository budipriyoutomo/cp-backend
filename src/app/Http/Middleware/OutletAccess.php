<?php

namespace App\Http\Middleware;

use App\Models\Outlet;
use App\Support\OutletGate;
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
 *
 * Aturan izinnya sendiri (admin, `users.outlet`, normalisasi kode) ada di
 * `App\Support\OutletGate`, dipakai bersama service yang mencari data lewat id.
 * Middleware ini hanya menentukan outlet mana yang diminta request.
 */
class OutletAccess
{
    /** Nama parameter outlet yang dipakai di seluruh API. */
    private const KEYS = ['outletId', 'outlet_id'];

    public function __construct(private readonly OutletGate $gate)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Tidak ada user berarti `auth:api` yang akan menolak. Menjawab 403 di
        // sini hanya menukar 401 yang benar dengan pesan yang salah.
        if (! $user) {
            return $next($request);
        }

        // Admin melihat semua outlet, sama seperti di OutletProvider frontend.
        if ($this->gate->isAdmin($user)) {
            return $next($request);
        }

        $outletId = $this->outletIdFrom($request);

        // `outlets.id` bertipe uuid di PostgreSQL. Membandingkannya dengan
        // string sembarang melempar "invalid input syntax for type uuid" — 500,
        // bukan 403. Bentuk yang salah dilewatkan, biar service yang menjawabnya.
        if ($outletId === null || ! Uuid::matches($outletId)) {
            return $next($request);
        }

        $outlet = Outlet::find($outletId);

        // Outlet tak dikenal bukan urusan izin. Biarkan service menjawab 404-nya
        // supaya pesan error tidak berubah jadi 403 yang menyesatkan. Gate
        // sendiri gagal-tertutup untuk id tak dikenal — karena itu modelnya
        // dicari di sini, bukan menyerahkan uuid mentah ke gate.
        if (! $outlet) {
            return $next($request);
        }

        if (! $this->gate->canAccess($user, $outlet)) {
            // Envelope sama dengan RoleMiddleware dan BaseApiController::error().
            return response()->json([
                'status'  => false,
                'message' => OutletGate::DENIED_MESSAGE,
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
}
