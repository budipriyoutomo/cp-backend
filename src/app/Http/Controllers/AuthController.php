<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\UserResource;

/**
 * Responses here go through BaseApiController like everywhere else, so the
 * envelope is `{ status, message, data }`. It used to be a hand-written
 * `{ success, ... }`, which made this the odd one out in the API.
 */
class AuthController extends BaseApiController
{
    public function login(Request $request)
    {
        $request->validate([
        'email' => 'required|email',
        'password' => 'required|string|min:6',
        ]);

        $credentials = $request->only('email', 'password');

        if (! $token = JWTAuth::attempt($credentials)) {
            return $this->error('Unauthorized', 401);
        }

        $user = auth()->user();

        return $this->success($this->tokenPayload($user, $token), 'Login successful');
    }

    public function loginByPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|min:6|max:10',
        ]);

        // Look up by blind index, then verify against the bcrypt hash. The
        // second check matters: the lookup column alone would authenticate on
        // an HMAC collision or on a stale value written outside the app.
        $user = User::where('pin_lookup', User::pinLookup($request->pin))->first();

        if (!$user || !$user->pin || !Hash::check($request->pin, $user->pin)) {
            return $this->error('PIN tidak valid', 401);
        }

        $token = JWTAuth::fromUser($user);

        return $this->success($this->tokenPayload($user, $token), 'Login successful');
    }

    public function me()
    {
        return $this->resource(new UserResource(auth()->user()));
    }

    public function logout()
    {
        auth()->logout();

        return $this->success(null, 'Successfully logged out');
    }

    public function refresh()
    {
        // Through the guard, not JWTAuth::refresh(): the facade has no token
        // bound to it here, while auth:api has already parsed the bearer token.
        $token = auth('api')->refresh();

        return $this->success([
            'token'      => $token,
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ], 'Token refreshed');
    }

    public function register(RegisterRequest  $request)
    {
        $user = User::create([
            'name'       => $request->name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'role'       => $request->role,
            'departemen' => $request->departemen,
            'outlet'     => $request->outlet,
            'module_app' => $request->module_app,
        ]);

        $token = JWTAuth::fromUser($user);

        return $this->success([
            'user'  => new UserResource($user),
            'token' => $token,
        ], 'User registered successfully', 201);
    }

    private function tokenPayload(User $user, string $token): array
    {
        return [
            'user'       => new UserResource($user),
            'token'      => $token,
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ];
    }
}
