<?php

namespace App\Http\Middleware;

use App\Models\AdminToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards the admin API: `Authorization: Bearer rmt_…` from POST /api/admin/login. */
class AuthenticateAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? AdminToken::findPlain($plain) : null;
        $admin = $token ? User::first() : null;

        if (!$token || !$admin) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'A valid admin token is required.',
            ], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // Not on every request: a polling app would otherwise write once a second.
        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('admin_token', $token);
        $request->setUserResolver(fn () => $admin);

        return $next($request);
    }
}
