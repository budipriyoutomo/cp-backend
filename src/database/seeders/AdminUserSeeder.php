<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the one admin account needed to log in and enter everything else.
 *
 * Credentials come from the environment and there is no default: a seeded
 * admin with a known password is a back door, and this seeder is meant to be
 * run against a real database.
 *
 *     BOOTSTRAP_ADMIN_NAME="Nama Admin"
 *     BOOTSTRAP_ADMIN_EMAIL="admin@maharasa.example"
 *     BOOTSTRAP_ADMIN_PASSWORD="..."
 *
 * Safe to re-run: matched by email, and the password is only set on creation
 * so a later rotation is not undone.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email    = trim((string) env('BOOTSTRAP_ADMIN_EMAIL', ''));
        $password = (string) env('BOOTSTRAP_ADMIN_PASSWORD', '');
        $name     = trim((string) env('BOOTSTRAP_ADMIN_NAME', 'Administrator'));

        if ($email === '' || $password === '') {
            throw new RuntimeException(
                'AdminUserSeeder butuh BOOTSTRAP_ADMIN_EMAIL dan BOOTSTRAP_ADMIN_PASSWORD di .env. '
                . 'Tidak ada nilai default — admin dengan password yang bisa ditebak sama saja dengan pintu belakang.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('BOOTSTRAP_ADMIN_PASSWORD minimal 8 karakter.');
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            $this->command?->warn("Admin {$email} sudah ada — password tidak diubah.");

            return;
        }

        User::create([
            'name'       => $name,
            'email'      => $email,
            'password'   => $password, // di-hash oleh cast 'hashed' di model
            'role'       => 'admin',
            'departemen' => 'Operation',
            'outlet'     => Outlet::pluck('code')->all(),
            'module_app' => ['admin', 'production', 'operation', 'report', 'kitchen'],
        ]);

        $this->command?->info("Admin dibuat: {$email}. Ganti passwordnya setelah login pertama.");
    }
}
