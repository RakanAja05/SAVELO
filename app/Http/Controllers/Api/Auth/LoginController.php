<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // <--- ADD THIS LINE
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Handle the login request (for route: login).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('Email atau password salah.', null, 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return ApiResponse::success('Login berhasil.', [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout the authenticated user (Sanctum).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success('Logout berhasil.');
    }
}
