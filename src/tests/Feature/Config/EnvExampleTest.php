<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

/**
 * `.env.example` adalah satu-satunya petunjuk yang dimiliki orang yang baru
 * meng-clone repo ini — `.env` asli di-gitignore.
 *
 * Test ini ada karena berkas itu pernah melenceng jauh: `JWT_SECRET` tidak
 * tercantum sama sekali, jadi setup baru gagal di seluruh jalur auth tanpa
 * petunjuk apa yang kurang. Kredensial RabbitMQ juga hilang, padahal
 * `../CLAUDE.md` menyatakan contohnya ada di sini.
 *
 * Yang dijaga hanya kunci yang ketiadaannya mematikan sesuatu — bukan seluruh
 * isi `config/`. Daftar lengkap akan jadi pekerjaan rumah yang tidak ada yang
 * mau merawat.
 */
class EnvExampleTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredKeys(): array
    {
        return [
            // Tanpa ini tidak ada satu pun request terautentikasi yang bisa jalan.
            'JWT_SECRET'         => ['JWT_SECRET', 'seluruh jalur auth mati'],
            'JWT_TTL'            => ['JWT_TTL', 'umur sesi jadi default diam'],
            'JWT_REFRESH_TTL'    => ['JWT_REFRESH_TTL', 'batas sesi jadi default diam'],

            // Worker POS tidak jalan, "Get Data POS" kosong.
            'RABBITMQ_HOST'      => ['RABBITMQ_HOST', 'worker POS tidak terhubung'],
            'RABBITMQ_PORT'      => ['RABBITMQ_PORT', 'worker POS tidak terhubung'],
            'RABBITMQ_USER'      => ['RABBITMQ_USER', 'worker POS tidak terhubung'],
            'RABBITMQ_PASSWORD'  => ['RABBITMQ_PASSWORD', 'worker POS tidak terhubung'],
            'RABBITMQ_VHOST'     => ['RABBITMQ_VHOST', 'worker POS tidak terhubung'],

            // Unggah foto waste di closing report gagal.
            'AWS_BUCKET'         => ['AWS_BUCKET', 'unggah foto waste gagal'],
            'AWS_ACCESS_KEY_ID'  => ['AWS_ACCESS_KEY_ID', 'unggah foto waste gagal'],

            // Seeder menolak jalan tanpa keduanya.
            'BOOTSTRAP_ADMIN_EMAIL'    => ['BOOTSTRAP_ADMIN_EMAIL', 'BootstrapSeeder menolak jalan'],
            'BOOTSTRAP_ADMIN_PASSWORD' => ['BOOTSTRAP_ADMIN_PASSWORD', 'BootstrapSeeder menolak jalan'],
        ];
    }

    /**
     * @dataProvider requiredKeys
     */
    public function test_env_example_declares_the_key(string $key, string $consequence): void
    {
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote($key, '/') . '=/m',
            $this->envExample(),
            "`{$key}` tidak ada di .env.example — kalau terlewat saat setup, {$consequence}."
        );
    }

    /**
     * Nilai rahasia tidak boleh ikut ke berkas yang di-commit. `JWT_SECRET=`
     * yang kosong adalah bentuk yang benar; yang terisi berarti secret bocor ke
     * riwayat git dan harus dirotasi, bukan sekadar dihapus.
     */
    public function test_env_example_ships_no_filled_secrets(): void
    {
        foreach (['JWT_SECRET', 'APP_KEY', 'AWS_SECRET_ACCESS_KEY', 'BOOTSTRAP_ADMIN_PASSWORD'] as $key) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($key, '/') . '=\s*$/m',
                $this->envExample(),
                "`{$key}` di .env.example harus kosong — berkas ini ikut ter-commit."
            );
        }
    }

    private function envExample(): string
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path, '.env.example hilang — setup baru tidak punya petunjuk sama sekali.');

        return (string) file_get_contents($path);
    }
}
