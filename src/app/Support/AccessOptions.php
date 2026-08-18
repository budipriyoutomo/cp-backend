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
     */
    public const ROLES = [
        'admin',
        'manager',
        'kitchen',
        'service',
        'operation',
        'production',
    ];

    /**
     * Nilai sah untuk tiap elemen array `users.module_app`.
     *
     * `app` adalah modul dasar tanpa halaman sendiri — frontend melewatinya
     * saat mencari modul tujuan redirect.
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
