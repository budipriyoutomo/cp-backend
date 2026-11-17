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

        // Middleware ini berjalan di grup `api`, SEBELUM `auth:api` di route —
        // dan guard default-nya `web`, jadi `$request->user()` di sini selalu
        // null untuk token JWT. Guard `api` dibaca eksplisit.
        //
        // Tanpa user yang sah tidak ada yang diputar ulang maupun disimpan:
        // `auth:api` akan menjawab 401, dan menjawab 409 di sini justru
        // memberi tahu orang tanpa token bahwa key itu pernah dipakai.
        $userId = $this->userIdOf($request);

        if ($userId === null) {
            return $next($request);
        }

        $existing = ProcessedRequest::where('request_id', $key)->first();

        if ($existing) {
            // Key tetap unik global (unique index di `request_id`), tapi hanya
            // boleh diputar ulang untuk pemiliknya, di endpoint yang sama.
            // Dulu respons — termasuk body-nya — diputar ulang untuk siapa pun
            // yang mengirim key itu, ke path mana pun.
            if (! $this->belongsTo($existing, $request, $userId)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Request ID sudah dipakai',
                    'errors'  => null,
                ], 409);
            }

            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('X-Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Only persist successful outcomes — those are the ones whose
        // re-execution would create duplicates. 4xx/5xx are left to run again.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->remember($request, $key, $userId, $response);
        }

        return $response;
    }

    /**
     * `users.id` auto-increment (bukan uuid), jadi dibandingkan sebagai string
     * supaya int vs string dari driver berbeda tidak membuat pemilik sah ditolak.
     */
    private function userIdOf(Request $request): ?string
    {
        $user = auth('api')->user();

        return $user ? (string) $user->getAuthIdentifier() : null;
    }

    private function belongsTo(ProcessedRequest $row, Request $request, string $userId): bool
    {
        return (string) $row->user_id === $userId
            && $row->path === $request->path()
            && $row->method === $request->method();
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

    private function remember(Request $request, string $key, string $userId, Response $response): void
    {
        try {
            ProcessedRequest::create([
                'request_id'      => $key,
                'method'          => $request->method(),
                'path'            => $request->path(),
                'user_id'         => $userId,
                'response_status' => $response->getStatusCode(),
                'response_body'   => $response->getContent(),
            ]);
        } catch (QueryException $e) {
            // A concurrent request already stored this key (unique violation) —
            // safe to ignore; the stored response will be replayed next time.
        }
    }
}
