<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Role `service` dihapus dari `AccessOptions::ROLES`; izinnya dilebur ke
 * `kitchen`.
 *
 * Tanpa migration ini baris lama menyimpan role yang tidak lagi sah: bukan
 * sekadar kotor, tapi memutus akses. `RoleMiddleware` mencocokkan string apa
 * adanya, jadi user `service` akan gagal di `role:admin,kitchen` dan kehilangan
 * seluruh master data — yang berarti `OutletProvider` di frontend tidak dapat
 * daftar outlet, dan layar dapur berhenti mengambil data sama sekali.
 *
 * `module_app` sengaja **tidak** disentuh. Modul `service` masih nilai yang sah
 * dan `app/kitchen/layout.tsx` masih menerimanya, jadi modul itulah yang tetap
 * memisahkan staf service dari staf kitchen setelah rolenya seragam — sekaligus
 * satu-satunya penanda yang tersisa untuk membalik migration ini.
 */
return new class extends Migration
{
    /**
     * Bentuk lama kolom `role` yang pernah ditulis ke DB.
     *
     * Kolomnya `varchar` dan hampir selalu berisi string polos, tapi
     * `RoleMiddleware::rolesOf()` masih menormalkan bentuk JSON array demi
     * baris lama — jadi baris seperti itu ikut diperlakukan sebagai `service`
     * di runtime, dan harus ikut dipindah di sini juga.
     */
    private const LEGACY_SERVICE_VALUES = [
        'service',
        '["service"]',
        '"service"',
    ];

    public function up(): void
    {
        DB::table('users')
            ->whereIn('role', self::LEGACY_SERVICE_VALUES)
            ->update(['role' => 'kitchen']);
    }

    public function down(): void
    {
        // Setelah `up()` tidak ada lagi yang membedakan bekas user service dari
        // user kitchen asli — kecuali `module_app`, yang tidak ikut diubah.
        // Itu penanda terbaik yang tersedia; user kitchen asli tidak pernah
        // memegang modul `service`, jadi tidak ada yang salah dipindah balik.
        $rows = DB::table('users')
            ->where('role', 'kitchen')
            ->whereNotNull('module_app')
            ->get(['id', 'module_app']);

        foreach ($rows as $row) {
            $modules = json_decode((string) $row->module_app, true);

            if (is_array($modules) && in_array('service', $modules, true)) {
                DB::table('users')
                    ->where('id', $row->id)
                    ->update(['role' => 'service']);
            }
        }
    }
};
