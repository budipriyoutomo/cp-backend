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
     *
     * Sejak middleware `module:` ada, daftar ini bukan lagi sekadar urusan
     * frontend — server memakai nilai yang sama untuk menutup grup route.
     * Menambah modul di sini berarti juga memutuskan grup route mana yang
     * dibukanya, dan halaman tujuannya di `frontend/lib/constants/access.ts`.
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
     * Modul yang hanya boleh dipegang role tertentu.
     *
     * Dua sumbu ini menjawab pertanyaan berbeda — `role` "boleh melakukan apa",
     * `module_app` "boleh sampai ke mana" — jadi umumnya bebas dikombinasikan.
     * Modul `admin` pengecualiannya: seluruh layar di baliknya (master, users,
     * import backdate) dijaga `role:admin` di server, jadi memberikannya ke role
     * lain menghasilkan halaman yang terbuka tapi setiap aksinya 403. Bukan
     * lubang keamanan — server tetap menahan — tapi kegagalan yang membingungkan
     * dan tidak ada gunanya dibiarkan bisa tersimpan.
     *
     * @var array<string, array<int, string>>
     */
    public const MODULE_ROLE_REQUIREMENTS = [
        'admin' => ['admin'],
    ];

    /**
     * Alasan kenapa kombinasi role + module_app ini tidak sah, atau `null`
     * kalau tidak apa-apa.
     *
     * @param  array<int, string>  $modules
     */
    public static function conflictFor(string $role, array $modules): ?string
    {
        foreach (self::MODULE_ROLE_REQUIREMENTS as $module => $allowedRoles) {
            if (in_array($module, $modules, true) && ! in_array($role, $allowedRoles, true)) {
                return sprintf(
                    'Modul `%s` hanya untuk role %s — role `%s` akan melihat halamannya tapi ditolak di setiap aksi.',
                    $module,
                    implode('/', $allowedRoles),
                    $role
                );
            }
        }

        return null;
    }

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
