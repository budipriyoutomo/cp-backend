<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\RegisterRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\UserResource;
use App\Http\Resources\LoginResource;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
        'email' => 'required|email',
        'password' => 'required|string|min:6',
        ]);

        $credentials = $request->only('email', 'password');

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $user = auth()->user();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ]
        ]);
    }

    public function loginByPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|min:6|max:10',
        ]);

        $user = User::where('pin', $request->pin) 
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'PIN tidak valid'
            ], 401);
        }

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ]
        ]);
    }

    public function me()
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'data' => new UserResource($user)
        ]);
    }

    public function logout()
    {
        auth()->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }

    public function refresh()
    {
        $token = JWTAuth::refresh();

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed',
            'data' => [
                'token' => $token,
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ]
        ]);
    }

    public function register(RegisterRequest  $request)
    {

        //$outlet = is_array($request->outlet) ? json_encode($request->outlet) : $request->outlet;

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

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ]
        ], 201);
    }
}
