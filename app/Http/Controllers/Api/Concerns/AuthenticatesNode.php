<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Client;
use Illuminate\Http\Request;

/** The node routes' own auth: the Bearer token of an approved node client. Not an admin token, not an account. */
trait AuthenticatesNode
{
    private function node(Request $request): Client
    {
        $token = $request->bearerToken();
        $node = $token ? Client::where('api_token', $token)->first() : null;

        abort_if($node === null || !$node->isNode() || $node->status !== 'approved', 401, 'Unknown node token.');

        return $node;
    }
}
