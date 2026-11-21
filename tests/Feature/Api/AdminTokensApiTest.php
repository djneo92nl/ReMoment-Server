<?php

namespace Tests\Feature\Api;

use App\Models\AdminToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** GET/DELETE /api/admin/tokens (docs/api/admin-tokens.md). */
class AdminTokensApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    public function test_every_route_needs_the_admin_token(): void
    {
        [$token] = AdminToken::issue('iPad');

        $this->assertNeedsAdminToken([['GET', '/api/admin/tokens'], ['DELETE', '/api/admin/tokens'], ['DELETE', "/api/admin/tokens/{$token->id}"]]);
        $this->assertDatabaseCount('admin_tokens', 1);
    }

    public function test_the_list_marks_the_calling_token_and_never_shows_a_hash(): void
    {
        $headers = $this->adminHeaders();
        AdminToken::issue('iPad');

        $response = $this->getJson('/api/admin/tokens', $headers)->assertOk();

        $response->assertJsonCount(2, 'data')->assertJsonStructure(['data' => [['id', 'name', 'last_used_at', 'last_used_ip', 'created_at', 'current']]]);
        $this->assertSame(['test'], collect($response->json('data'))->where('current', true)->pluck('name')->all());
        $this->assertStringNotContainsString('"token"', $response->getContent());
        $this->assertStringNotContainsString(AdminToken::first()->token, $response->getContent());
    }

    public function test_one_token_can_be_revoked_and_stops_working(): void
    {
        $headers = $this->adminHeaders();
        [$other, $plain] = AdminToken::issue('iPad');

        $this->deleteJson("/api/admin/tokens/{$other->id}", [], $headers)->assertOk()->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('admin_tokens', ['id' => $other->id]);
        $this->getJson('/api/admin/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
        $this->deleteJson("/api/admin/tokens/{$other->id}", [], $headers)->assertNotFound();
    }

    public function test_revoke_all_can_keep_the_calling_token(): void
    {
        $headers = $this->adminHeaders();
        AdminToken::issue('iPad');
        AdminToken::issue('iPhone');

        $this->deleteJson('/api/admin/tokens?keep_current=1', [], $headers)->assertOk()->assertJsonPath('revoked', 2);
        $this->assertDatabaseCount('admin_tokens', 1);

        $this->deleteJson('/api/admin/tokens', [], $headers)->assertOk()->assertJsonPath('revoked', 1);
        $this->getJson('/api/admin/me', $headers)->assertStatus(401);
    }
}
