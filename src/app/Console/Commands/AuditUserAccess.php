<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AccessOptions;
use Illuminate\Console\Command;

/**
 * Laporkan akses tiap user, dan tandai yang timpang.
 *
 * Dibuat untuk dijalankan **sebelum** `module:` dinyalakan di produksi. Sampai
 * sekarang `module_app` hanya dipakai frontend, jadi tidak ada yang memaksanya
 * konsisten: user bisa punya modul yang rolenya tidak mendukung, atau tidak
 * punya modul sama sekali dan tetap memanggil API lewat token. Begitu server
 * ikut memeriksa, keduanya berubah jadi 403 — dan lebih baik ketahuan di sini
 * daripada dari operator yang layarnya mendadak kosong.
 */
class AuditUserAccess extends Command
{
    protected $signature = 'users:audit-access {--problems-only : hanya tampilkan user yang bermasalah}';

    protected $description = 'Periksa keselarasan role, module_app, dan outlet tiap user';

    public function handle(): int
    {
        $users    = User::orderBy('name')->get();
        $rows     = [];
        $problems = 0;

        foreach ($users as $user) {
            $issues = $this->issuesFor($user);

            if ($issues !== [] ) {
                $problems++;
            } elseif ($this->option('problems-only')) {
                continue;
            }

            $rows[] = [
                $user->id,
                $user->name,
                (string) $user->role,
                implode(', ', $this->arrayOf($user->module_app)) ?: '—',
                implode(', ', $this->arrayOf($user->outlet)) ?: '—',
                $issues === [] ? 'ok' : implode('; ', $issues),
            ];
        }

        if ($rows === []) {
            $this->info('Tidak ada yang perlu dilaporkan.');

            return self::SUCCESS;
        }

        $this->table(['id', 'nama', 'role', 'module_app', 'outlet', 'catatan'], $rows);

        $this->newLine();
        $this->line("Total user: {$users->count()}");

        if ($problems > 0) {
            $this->warn("{$problems} user bermasalah — perbaiki sebelum `module:` dinyalakan di produksi.");

            return self::FAILURE;
        }

        $this->info('Semua user selaras.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function issuesFor(User $user): array
    {
        $issues  = [];
        $role    = (string) $user->role;
        $modules = $this->arrayOf($user->module_app);
        $outlets = $this->arrayOf($user->outlet);

        if (! in_array($role, AccessOptions::ROLES, true)) {
            $issues[] = "role `{$role}` tidak sah";
        }

        foreach (array_diff($modules, AccessOptions::MODULE_APPS) as $unknown) {
            $issues[] = "modul `{$unknown}` tidak sah";
        }

        // Modul kosong = tidak punya akses. Sudah tertutup di frontend, tapi
        // sampai `module:` terpasang ia masih bisa memanggil API lewat token.
        if (array_diff($modules, ['app']) === []) {
            $issues[] = 'tidak punya modul berhalaman';
        }

        if (in_array('admin', $modules, true) && $role !== 'admin') {
            $issues[] = "modul `admin` butuh role admin, bukan `{$role}`";
        }

        // Admin memang lintas outlet (`OutletAccess` melewatinya).
        if ($outlets === [] && $role !== 'admin') {
            $issues[] = 'tidak punya outlet';
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function arrayOf($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
