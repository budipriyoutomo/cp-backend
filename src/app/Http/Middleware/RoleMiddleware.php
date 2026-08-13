<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {

        $user = auth()->user();

        if (! $user || ! array_intersect($this->rolesOf($user), $roles)) {
            // Same envelope as BaseApiController::error().
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized access',
                'errors'  => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * `users.role` is documented as a JSON array but every code path so far
     * writes a plain string, and older rows may hold either. Normalise both
     * shapes so a legitimately-roled user is never locked out by the format.
     */
    private function rolesOf($user): array
    {
        $role = $user->role;

        if (is_array($role)) {
            return $role;
        }

        if (is_string($role)) {
            $decoded = json_decode($role, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [$role];
    }
}
