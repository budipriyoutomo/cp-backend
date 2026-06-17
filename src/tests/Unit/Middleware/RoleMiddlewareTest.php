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
        $user = new User(['role' => 'service']);

        $response = $this->runMiddleware($user, ['admin']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertSame('Unauthorized access', $response->getData()->message);
    }

    public function test_rejects_when_no_user_is_authenticated(): void
    {
        $response = $this->runMiddleware(null, ['admin']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
    }
}
