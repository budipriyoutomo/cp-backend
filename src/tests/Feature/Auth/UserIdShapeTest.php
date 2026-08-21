<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * `id` user harus berbentuk string di setiap endpoint yang mengirimnya.
 *
 * `users.id` adalah satu-satunya auto-increment di skema ini (sisanya UUID),
 * jadi tanpa cast ia terkirim sebagai angka JSON. Frontend sudah lama membaca
 * hasil `/login` sebagai string lewat `String(apiUser.id)`, tapi `/auth/me`
 * dipakai apa adanya — jadi satu user punya dua bentuk id tergantung ia baru
 * login atau baru me-restore sesi, dan bentuknya berubah di tengah sesi karena
 * `refreshUser()` menimpa nilai dari login.
 *
 * Tidak ada yang membandingkan id ini sekarang, jadi test ini menjaga sesuatu
 * yang belum pernah rusak — memang itu maksudnya. Yang akan memicunya adalah
 * perbandingan `===` atau pemakaian sebagai kunci objek, dan keduanya gagal
 * diam-diam: tidak ada error, hanya hasil yang salah.
 */
class UserIdShapeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_login_returns_the_id_as_a_string(): void
    {
        $user = $this->userWithRole('admin');

        $response = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertIsString($response->json('data.user.id'));
        $this->assertSame((string) $user->id, $response->json('data.user.id'));
    }

    public function test_pin_login_returns_the_id_as_a_string(): void
    {
        $user = $this->userWithRole('kitchen');
        $user->pin = '135790';
        $user->save();

        $response = $this->postJson('/api/login-pin', ['pin' => '135790'])->assertOk();

        $this->assertIsString($response->json('data.user.id'));
        $this->assertSame((string) $user->id, $response->json('data.user.id'));
    }

    /**
     * Jalur yang dulu menyimpang. `/login` sudah dinormalkan di frontend,
     * `/auth/me` tidak — dan justru inilah yang jalan tiap halaman dibuka.
     */
    public function test_me_returns_the_id_as_a_string(): void
    {
        $user  = $this->userWithRole('admin');
        $token = JWTAuth::fromUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertIsString($response->json('data.id'));
        $this->assertSame((string) $user->id, $response->json('data.id'));
    }

    public function test_login_and_me_agree_on_the_id(): void
    {
        $user = $this->userWithRole('admin');

        $login = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $me = $this->withHeader('Authorization', 'Bearer ' . $login->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk();

        // Inti masalahnya: bukan sekadar sama nilainya, tapi sama bentuknya.
        $this->assertSame($login->json('data.user.id'), $me->json('data.id'));
    }

    public function test_the_admin_user_list_agrees_too(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin, 'api')->getJson('/api/users')->assertOk();

        $this->assertIsString($response->json('data.0.id'));
    }
}
