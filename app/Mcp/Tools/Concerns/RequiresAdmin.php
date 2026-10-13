<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\AdminToken;
use Laravel\Mcp\Response;

/** Tools that change configuration or maintain the library: only with a valid admin bearer token, like the REST admin routes. */
trait RequiresAdmin
{
    /** Hides the tool from clients without a valid admin token. */
    public function shouldRegister(): bool
    {
        return $this->isAdmin();
    }

    protected function isAdmin(): bool
    {
        $plain = request()->bearerToken();

        return $plain && AdminToken::findPlain($plain) !== null;
    }

    protected function adminDenied(): ?Response
    {
        return $this->isAdmin() ? null : Response::error('unauthenticated (401): A valid admin token is required (Authorization: Bearer rmt_… from POST /api/admin/login).');
    }
}
