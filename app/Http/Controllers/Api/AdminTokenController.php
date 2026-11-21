<?php

namespace App\Http\Controllers\Api;

use App\Domain\Admin\AdminTokens;
use App\Http\Controllers\Controller;
use App\Models\AdminToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app sign-ins of Settings › Admin & Apps for apps (docs/api/admin-tokens.md). Admin token only. */
class AdminTokenController extends Controller
{
    public function index(Request $request, AdminTokens $tokens): JsonResponse
    {
        $current = $request->attributes->get('admin_token');

        return response()->json(['data' => $tokens->all()->map(fn (AdminToken $t) => $tokens->toApi($t, $current))->values()]);
    }

    /** Revoking the calling token is allowed: it is the same as signing out. */
    public function destroy(AdminTokens $tokens, AdminToken $token): JsonResponse
    {
        $tokens->revoke($token);

        return response()->json(['status' => 'ok']);
    }

    /** Revokes every token; `?keep_current=1` spares the caller's own, so an app can sign the others out. */
    public function destroyAll(Request $request, AdminTokens $tokens): JsonResponse
    {
        $keep = $request->boolean('keep_current') ? $request->attributes->get('admin_token') : null;

        return response()->json(['status' => 'ok', 'revoked' => $tokens->revokeAll($keep)]);
    }
}
