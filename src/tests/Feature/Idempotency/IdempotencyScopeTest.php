<?php

namespace Tests\Feature\Idempotency;

use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Respons tersimpan dulu dicari dengan `request_id` saja, lalu diputar ulang
 * untuk siapa pun ke path mana pun. Key yang bocor (log, antrean offline di
 * tablet bersama) cukup untuk membaca respons user lain.
 *
 * Sengaja memakai token JWT sungguhan di header, bukan `actingAs()`.
 * `actingAs($u, 'api')` sekaligus menjadikan `api` guard default, padahal di
 * produksi guard default adalah `web` dan middleware ini berjalan **sebelum**
 * `auth:api` — `$request->user()` di sana selalu null. Test dengan actingAs
 * akan lulus untuk kode yang di produksi tidak pernah mengenali user.
 */
class IdempotencyScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    private Outlet $outlet;
    private PlateColors $color;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = $this->createOutlet();
        $this->color  = $this->createPlateColor();
    }

    private function salesPayload(int $posSold = 5): array
    {
        return [
            'outlet_id' => $this->outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'draft',
            'items'     => [
                ['plate_color_id' => $this->color->id, 'pos_sold' => $posSold, 'production_sold' => 5],
            ],
        ];
    }

    /** Request sebagai `$user` lewat Bearer token, tanpa sisa state guard dari request sebelumnya. */
    private function as(User $user, string $key): static
    {
        // Dua lapis state yang di produksi mati bersama proses tiap request,
        // tapi di test bertahan antar-request: user cache di guard, dan token
        // yang sudah di-parse. Guard memakai singleton `tymon.jwt`, bukan
        // `tymon.jwt.auth` di balik facade JWTAuth — keduanya harus dibersihkan.
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        JWTAuth::unsetToken();

        return $this->withHeaders([
            'Authorization'       => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Client-Request-Id' => $key,
        ]);
    }

    public function test_same_user_same_path_still_replays(): void
    {
        $user = $this->userWithRole('operation');

        $first = $this->as($user, 'key-1')->postJson('/api/sales', $this->salesPayload())->assertStatus(201);

        $this->as($user, 'key-1')->postJson('/api/sales', $this->salesPayload(99))
            ->assertStatus(201)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        // Diputar ulang, bukan dijalankan: pos_sold 99 tidak pernah tersimpan.
        $this->assertDatabaseMissing('sales_items', ['pos_sold' => 99]);
    }

    public function test_the_stored_row_records_the_user_even_before_auth_middleware(): void
    {
        $user = $this->userWithRole('operation');

        $this->as($user, 'key-1')->postJson('/api/sales', $this->salesPayload())->assertStatus(201);

        $this->assertDatabaseHas('processed_requests', ['request_id' => 'key-1', 'user_id' => $user->id]);
    }

    public function test_another_user_cannot_replay_someone_elses_response(): void
    {
        $alice = $this->userWithRole('operation');
        $bob   = $this->userWithRole('operation');

        $this->as($alice, 'key-1')->postJson('/api/sales', $this->salesPayload())->assertStatus(201);

        $this->as($bob, 'key-1')->postJson('/api/sales', $this->salesPayload(99))
            ->assertStatus(409)
            ->assertHeaderMissing('X-Idempotent-Replay')
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Request ID sudah dipakai')
            ->assertJsonMissingPath('data');

        // Tidak dijalankan juga.
        $this->assertDatabaseMissing('sales_items', ['pos_sold' => 99]);
    }

    public function test_the_same_key_on_another_path_is_refused(): void
    {
        $user = $this->userWithRole('operation');

        $this->as($user, 'key-1')->postJson('/api/sales', $this->salesPayload())->assertStatus(201);

        $this->as($user, 'key-1')->postJson('/api/production/close-day', ['outletId' => $this->outlet->id])
            ->assertStatus(409)
            ->assertHeaderMissing('X-Idempotent-Replay');

        $this->assertDatabaseCount('close_day_logs', 0);
    }
}
