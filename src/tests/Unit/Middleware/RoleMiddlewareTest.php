<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RoleMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    private function runMiddleware(?User $user, array $allowedRoles)
    {
        if ($user) {
            Auth::setUser($user);
        }

        $middleware = new RoleMiddleware();

        return $middleware->handle(
            Request::create('/test', 'GET'),
            fn ($request) => response()->json(['passed' => true]),
            ...$allowedRoles
        );
    }

    public function test_passes_when_user_role_is_allowed(): void
    {
        $user = new User(['role' => 'admin']);

        $response = $this->runMiddleware($user, ['admin', 'kitchen']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->passed);
    }

    public function test_rejects_when_user_role_is_not_allowed(): void
    {
        $user = new User(['role' => 'operation']);

        $response = $this->runMiddleware($user, ['admin']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->status);
        $this->assertSame('Unauthorized access', $response->getData()->message);
    }

    public function test_rejects_when_no_user_is_authenticated(): void
    {
        $response = $this->runMiddleware(null, ['admin']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | `users.role` shape tolerance
    |--------------------------------------------------------------------------
    | The docs describe role as a JSON array, the code writes a plain string,
    | and an older database may hold either. All three must behave the same,
    | otherwise a legitimately-roled user is locked out by a storage detail.
    */

    public function test_accepts_a_role_stored_as_a_php_array(): void
    {
        $user = new User();
        $user->role = ['kitchen', 'production'];

        $response = $this->runMiddleware($user, ['admin', 'kitchen']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_accepts_a_role_stored_as_a_json_string(): void
    {
        $user = new User(['role' => '["manager"]']);

        $response = $this->runMiddleware($user, ['admin', 'manager']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_still_rejects_an_array_role_with_no_overlap(): void
    {
        $user = new User(['role' => '["operation"]']);

        $response = $this->runMiddleware($user, ['admin']);

        $this->assertSame(403, $response->getStatusCode());
    }
}
