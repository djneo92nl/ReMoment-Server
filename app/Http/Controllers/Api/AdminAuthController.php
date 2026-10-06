<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Token login for the single shared admin, for apps that can't use the web session (docs/api/admin-auth.md).
 * Like the web login it is password-only: there is exactly one admin account.
 */
class AdminAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $key = 'admin-api-login|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'too_many_attempts',
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429, ['Retry-After' => $seconds]);
        }

        $admin = User::first();

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            RateLimiter::hit($key);

            return response()->json([
                'error' => 'invalid_credentials',
                'message' => 'The password is incorrect.',
            ], 401);
        }

        RateLimiter::clear($key);

        [$token, $plain] = AdminToken::issue($data['device_name'] ?? null);

        return response()->json([
            'token' => $plain,
            'token_id' => $token->id,
            'name' => $token->name,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AdminToken $token */
        $token = $request->attributes->get('admin_token');

        return response()->json([
            'authenticated' => true,
            'token_id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('admin_token')->delete();

        return response()->json(['status' => 'ok']);
    }
}
