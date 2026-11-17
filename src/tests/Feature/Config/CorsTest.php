<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

/**
 * `*.vusercontent.net` adalah domain preview v0.app — siapa pun bisa membuat
 * halaman di sana. Selama pola itu ada di `allowed_origins_patterns`, halaman
 * buatan orang lain boleh memanggil API ini dari browser korban. Pola tambahan
 * sekarang datang dari `CORS_EXTRA_ORIGIN_PATTERNS`, yang kosong di produksi.
 */
class CorsTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN'                        => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
    }

    public function test_v0_preview_origins_are_not_allowed_by_default(): void
    {
        $this->assertSame([], config('cors.allowed_origins_patterns'));

        $this->preflight('https://evil.vusercontent.net')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_listed_origins_are_still_allowed(): void
    {
        $this->preflight('http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_extra_patterns_come_from_the_environment(): void
    {
        $config = $this->corsConfigWithEnv('#^https://.*\.vusercontent\.net$#, #^https://preview\.example\.com$#');

        $this->assertSame(
            ['#^https://.*\.vusercontent\.net$#', '#^https://preview\.example\.com$#'],
            $config['allowed_origins_patterns']
        );

        $this->assertSame([], $this->corsConfigWithEnv('')['allowed_origins_patterns']);
    }

    public function test_an_enabled_extra_pattern_is_honoured(): void
    {
        config(['cors.allowed_origins_patterns' => ['#^https://.*\.vusercontent\.net$#']]);

        $this->preflight('https://preview.vusercontent.net')
            ->assertHeader('Access-Control-Allow-Origin', 'https://preview.vusercontent.net');
    }

    /**
     * Evaluasi ulang `config/cors.php` dengan env tertentu. Ditulis ke ketiga
     * tempat yang dibaca `env()` — lihat BootstrapSeederTest untuk alasannya.
     */
    private function corsConfigWithEnv(string $value): array
    {
        $key = 'CORS_EXTRA_ORIGIN_PATTERNS';

        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;

        try {
            return require config_path('cors.php');
        } finally {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}
