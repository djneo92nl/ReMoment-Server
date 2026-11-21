<?php

namespace Tests\Support;

use App\Models\AdminToken;
use App\Models\User;

/** Tests of `admin.token` routes: the one shared admin and a bearer token for it (docs/api/admin-auth.md). */
trait SignsInAsAdmin
{
    /** @return array<string, string> the Authorization header of a fresh admin token */
    protected function adminHeaders(): array
    {
        User::query()->exists() || User::factory()->create();
        [, $plain] = AdminToken::issue('test');

        return ['Authorization' => 'Bearer '.$plain];
    }

    /** @param  list<array{0: string, 1: string}>  $routes  [method, uri] pairs that need the token */
    protected function assertNeedsAdminToken(array $routes): void
    {
        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        }
    }
}
