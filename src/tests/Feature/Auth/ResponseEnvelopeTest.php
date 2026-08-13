<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Every endpoint answers with the same envelope: `{ status, message, data }`
 * on success and `{ status, message, errors }` on failure.
 *
 * Three shapes used to coexist — AuthController and ProductionController wrote
 * `{ success, data }` by hand, SalesController returned a bare JsonResource
 * (`{ data }` only) — and each frontend service had to special-case its own.
 */
class ResponseEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    public function test_login_uses_the_standard_envelope(): void
    {
        $this->userWithRole('admin');
        $user = \App\Models\User::first();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['status', 'message', 'data'])
            ->assertJsonPath('status', true)
            ->assertJsonMissingPath('success');
    }

    public function test_a_failed_login_uses_the_standard_error_envelope(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrongpass'])
            ->assertStatus(401)
            ->assertJsonStructure(['status', 'message', 'errors'])
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('success');
    }

    public function test_sales_endpoints_use_the_standard_envelope(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();

        $created = $this->postJson('/api/sales', [
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'draft',
            'items'     => [
                ['plate_color_id' => $color->id, 'pos_sold' => 5, 'production_sold' => 5],
            ],
        ]);

        $created->assertStatus(201)
            ->assertJsonStructure(['status', 'message', 'data'])
            ->assertJsonPath('status', true);

        // Listing goes through resource() too, paginator meta included.
        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonStructure(['status', 'message', 'data', 'meta'])
            ->assertJsonPath('status', true);

        $this->getJson('/api/sales/' . $created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('status', true);
    }

    public function test_production_list_endpoints_use_the_standard_envelope(): void
    {
        $this->actingAsRole('admin');
        $outlet = $this->createOutlet();
        $menu   = $this->createMenu();
        $this->createProductionItem($outlet, $menu);

        $this->getJson('/api/production/items?outletId=' . $outlet->id . '&date=' . now()->toDateString())
            ->assertOk()
            ->assertJsonStructure(['status', 'message', 'data'])
            ->assertJsonPath('status', true)
            ->assertJsonMissingPath('success');

        $this->getJson(
            '/api/reports/production-menu-detail?outletId=' . $outlet->id
            . '&date=' . now()->toDateString()
            . '&plateColorId=' . $menu->plate_color_id
        )
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonMissingPath('success');
    }

    public function test_role_rejection_uses_the_standard_error_envelope(): void
    {
        $this->actingAsRole('kitchen');

        $this->postJson('/api/master/platecolor', ['platename' => 'X', 'price' => 1])
            ->assertStatus(403)
            ->assertJsonStructure(['status', 'message', 'errors'])
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('success');
    }

    public function test_validation_failures_use_the_standard_error_envelope(): void
    {
        // Two different FormRequest base classes produce this; both must match.
        $this->postJson('/api/register', [])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('success');

        $this->actingAsRole('admin');
        $this->postJson('/api/production/produce', [])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('success');
    }

    public function test_refresh_issues_a_new_token(): void
    {
        // actingAs() is not enough here: refresh() and logout() operate on the
        // actual bearer token, so the request has to carry a real one.
        $user  = $this->userWithRole('admin');
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['token', 'expires_in']])
            ->assertJsonMissingPath('success');
    }

    public function test_me_and_logout_use_the_standard_envelope(): void
    {
        $user  = $this->userWithRole('admin');
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $user->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('status', true);
    }
}
