<?php

namespace App\Http\Middleware;

use App\Models\ProcessedRequest;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    /**
     * Mutating methods that can safely be made idempotent.
     */
    private const MUTATING = ['POST', 'PUT', 'PATCH'];

    /**
     * Endpoints that must always run fresh (auth flows issue new tokens, etc.).
     */
    private const SKIP_PATHS = ['login', 'login-pin', 'logout', 'register', 'auth/refresh'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Client-Request-Id');

        if (! $key || ! $this->isIdempotent($request)) {
            return $next($request);
        }

        // Replay the stored outcome if this request was already processed.
        $existing = ProcessedRequest::where('request_id', $key)->first();

        if ($existing) {
            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('X-Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Only persist successful outcomes — those are the ones whose
        // re-execution would create duplicates. 4xx/5xx are left to run again.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->remember($request, $key, $response);
        }

        return $response;
    }

    private function isIdempotent(Request $request): bool
    {
        if (! in_array($request->method(), self::MUTATING, true)) {
            return false;
        }

        foreach (self::SKIP_PATHS as $path) {
            if (str_contains($request->path(), $path)) {
                return false;
            }
        }

        return true;
    }

    private function remember(Request $request, string $key, Response $response): void
    {
        try {
            ProcessedRequest::create([
                'request_id'      => $key,
                'method'          => $request->method(),
                'path'            => $request->path(),
                'user_id'         => optional($request->user())->id,
                'response_status' => $response->getStatusCode(),
                'response_body'   => $response->getContent(),
            ]);
        } catch (QueryException $e) {
            // A concurrent request already stored this key (unique violation) —
            // safe to ignore; the stored response will be replayed next time.
        }
    }
}
