<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Akun kitchen & service per outlet — satu pasang untuk tiap outlet.
 *
 *     php artisan db:seed --class=StaffUserSeeder
 *
 * Password dan PIN di bawah ini adalah kredensial awal yang SUDAH TERTULIS DI
 * REPO, jadi siapa pun yang bisa membaca kode ini bisa login. Itu sebabnya
 * seeder ini sengaja TIDAK dipanggil dari BootstrapSeeder dan menolak jalan di
 * environment production kecuali STAFF_SEED_ALLOW_PRODUCTION=true.
 * Ganti password + PIN setelah login pertama.
 *
 * Aman diulang: dicocokkan lewat email. Password dan PIN hanya ditulis saat
 * user dibuat, jadi rotasi yang sudah dilakukan tidak ditimpa seeder.
 */
class StaffUserSeeder extends Seeder
{
    /**
     * [nama, email, password, pin, module_app, outlet]
     *
     * Kolom kelima dulu `role`. Sejak role `service` dihapus semua staf dapur
     * ber-role `kitchen`, jadi yang tersisa membedakan mereka cuma `module_app`
     * — dan itulah yang disimpan di sini.
     *
     * `departemen` = Operation untuk semua baris, lihat DEPARTEMEN di bawah.
     */
    private const STAFF = [
        ['Kitchen TSM', 'kitchentsm@maharasa.id', 'kittsm2026', '269301', 'kitchen', 'STTSM'],
        ['Service TSM', 'servicetsm@maharasa.id', 'sertsm2026', '630653', 'service', 'STTSM'],
        ['Kitchen JWB', 'kitchenjwb@maharasa.id', 'kitjwb2027', '467462', 'kitchen', 'STJWB'],
        ['Service JWB', 'servicejwb@maharasa.id', 'serjwb2027', '615369', 'service', 'STJWB'],
        ['Kitchen PVJ', 'kitchenpvj@maharasa.id', 'kitpvj2028', '806081', 'kitchen', 'STPVJ'],
        ['Service PVJ', 'servicepvj@maharasa.id', 'serpvj2028', '543470', 'service', 'STPVJ'],
        ['Kitchen FMB', 'kitchenfmb@maharasa.id', 'kitfmb2029', '908296', 'kitchen', 'STFMB'],
        ['Service FMB', 'servicefmb@maharasa.id', 'serfmb2029', '895457', 'service', 'STFMB'],
        ['Kitchen P23', 'kitchenp23@maharasa.id', 'kitp232030', '511719', 'kitchen', 'STP23'],
        ['Service P23', 'servicep23@maharasa.id', 'serp232030', '759341', 'service', 'STP23'],
    ];

    private const DEPARTEMEN = 'Operation';

    /**
     * Semua staf dapur memakai role yang sama.
     *
     * Role `service` dulu dipisah, tapi izinnya di server persis sama dengan
     * `kitchen` — keduanya hanya boleh membaca master — jadi pemisahannya tidak
     * pernah menjaga apa pun. Pembagian tugasnya sekarang lewat `module_app`.
     */
    private const ROLE = 'kitchen';

    public function run(): void
    {
        if (app()->environment('production') && !env('STAFF_SEED_ALLOW_PRODUCTION', false)) {
            throw new RuntimeException(
                'StaffUserSeeder memakai password & PIN yang tertulis di repo. '
                . 'Kalau memang disengaja di production, set STAFF_SEED_ALLOW_PRODUCTION=true di .env, '
                . 'lalu ganti semua password dan PIN setelah login pertama.'
            );
        }

        $knownOutlets = Outlet::pluck('code')
            ->mapWithKeys(fn ($code) => [strtolower((string) $code) => true])
            ->all();

        $created = 0;
        $skipped = 0;

        foreach (self::STAFF as [$name, $email, $password, $pin, $module, $outlet]) {
            if (User::where('email', $email)->exists()) {
                $this->command?->warn("Lewati {$email} — sudah ada, password & PIN tidak diubah.");
                $skipped++;

                continue;
            }

            if (!isset($knownOutlets[strtolower($outlet)])) {
                // Bukan error fatal: `users.outlet` cuma array kode, tidak ada FK.
                // Tapi user tanpa outlet yang cocok tidak akan melihat data apa pun.
                $this->command?->warn(
                    "Outlet {$outlet} belum ada di tabel outlets — {$email} tetap dibuat, "
                    . 'tapi seed outletnya dulu (BOOTSTRAP_OUTLETS) supaya datanya kelihatan.'
                );
            }

            User::create([
                'name'       => $name,
                'email'      => $email,
                'password'   => $password, // di-hash oleh cast 'hashed' di model
                'pin'        => $pin,      // mutator mengisi bcrypt + pin_lookup
                'role'       => self::ROLE,
                'departemen' => self::DEPARTEMEN,
                'outlet'     => [$outlet],
                'module_app' => [$module],
            ]);

            $created++;
            $this->command?->info("User dibuat: {$email} ({$module} @ {$outlet})");
        }

        $this->command?->info("Selesai — {$created} dibuat, {$skipped} dilewati.");

        if ($created > 0) {
            $this->command?->warn('Password dan PIN awal ada di kode seeder ini. Ganti setelah login pertama.');
        }
    }
}
