<?php

namespace App\Support;

/**
 * Daftar role dan module_app yang sah.
 *
 * Sengaja static, bukan tabel: nilainya ikut kode — `role` dipakai
 * `RoleMiddleware` dan `module_app` dipakai `AuthGuard`/`SidebarNav` di
 * frontend, jadi menambah baris di DB tanpa menambah kode tidak memberi akses
 * ke apa pun. Menaruhnya di DB hanya memindahkan daftar mati ke tempat yang
 * lebih jauh dari kode yang membacanya.
 *
 * Tambah nilai baru di sini DAN di `frontend/lib/constants/access.ts` —
 * keduanya harus sama.
 */
final class AccessOptions
{
    /**
     * Nilai sah untuk kolom `users.role`.
     *
     * Kolom ini bertipe string (bukan JSON array, walau sempat
     * terdokumentasi begitu) — satu user satu role.
     *
     * Role `service` sudah dihapus: izinnya di server identik dengan `kitchen`
     * (keduanya hanya membaca master), jadi memisahkannya tidak pernah menjaga
     * apa pun. Baris lama dipindah ke `kitchen` oleh migration
     * `2026_08_19_000000_migrate_service_role_to_kitchen`. Modul `service` di
     * MODULE_APPS sengaja tetap ada — itu sumbu lain, lihat catatannya di bawah.
     */
    public const ROLES = [
        'admin',
        'manager',
        'kitchen',
        'operation',
        'production',
    ];

    /**
     * Nilai sah untuk tiap elemen array `users.module_app`.
     *
     * `app` adalah modul dasar tanpa halaman sendiri — frontend melewatinya
     * saat mencari modul tujuan redirect.
     *
     * `service` masih di sini walau role bernama sama sudah dihapus: modul dan
     * role adalah dua sumbu terpisah, dan `app/kitchen/layout.tsx` tetap
     * menerima modul `service` sebagai jalan masuk ke layar dapur.
     */
    public const MODULE_APPS = [
        'app',
        'production',
        'kitchen',
        'service',
        'report',
        'admin',
        'operation',
    ];

    /**
     * @return array<int, string>
     */
    public static function roles(): array
    {
        return self::ROLES;
    }

    /**
     * @return array<int, string>
     */
    public static function moduleApps(): array
    {
        return self::MODULE_APPS;
    }
}
