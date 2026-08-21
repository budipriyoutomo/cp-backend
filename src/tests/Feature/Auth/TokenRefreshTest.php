<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * `/auth/refresh` untuk token yang sudah kedaluwarsa.
 *
 * Ini satu-satunya keadaan yang penting: selama token masih hidup, tidak ada
 * yang perlu ditukar. Versi sebelumnya memasang `auth:api` di rute ini, dan
 * guard itu menolak token mati dengan 401 sebelum controller jalan — jadi
 * endpointnya hanya melayani kasus yang tidak membutuhkannya. Tablet dapur yang
 * membuka PWA sepanjang shift kehilangan sesi tiap 60 menit karenanya.
 */
class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_expired_token_can_still_be_exchanged(): void
    {
        $user  = $this->userWithRole('admin');
        $token = JWTAuth::fromUser($user);

        // TTL default 60 menit; `refresh_ttl` 14 hari. Di antara keduanya token
        // sudah mati tapi sesinya belum.
        Carbon::setTestNow(now()->addMinutes(90));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['token', 'expires_in']]);

        // Token baru harus benar-benar bisa dipakai, bukan sekadar terbentuk.
        $this->withHeader('Authorization', 'Bearer ' . $response->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);
    }

    public function test_a_still_valid_token_can_be_exchanged_too(): void
    {
        $token = JWTAuth::fromUser($this->userWithRole('admin'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('status', true);
    }

    /**
     * Batas sesi yang sebenarnya. Lewat sini, refresh harus berhenti menolong —
     * kalau tidak, token yang bocor berlaku selamanya selama rajin ditukar.
     */
    public function test_a_token_past_the_refresh_window_is_rejected(): void
    {
        $token = JWTAuth::fromUser($this->userWithRole('admin'));

        Carbon::setTestNow(now()->addDays(15));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('status', false);
    }

    public function test_a_token_from_a_logged_out_session_is_rejected(): void
    {
        $token = JWTAuth::fromUser($this->userWithRole('admin'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('status', false);
    }

    public function test_the_old_token_stops_working_once_it_has_been_exchanged(): void
    {
        $token = JWTAuth::fromUser($this->userWithRole('admin'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertOk();

        // Blacklist aktif: token lama tidak boleh jadi sesi kedua.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertStatus(401);
    }

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->postJson('/api/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('status', false);
    }

    public function test_a_malformed_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer bukan-token')
            ->postJson('/api/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('status', false);
    }
}
