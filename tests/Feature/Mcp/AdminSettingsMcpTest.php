<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** MCP tools for admin tokens, audio analysis, library settings and player housekeeping; they only call the REST API. */
class AdminSettingsMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['analysis.enabled' => true, 'profile.current' => 'full']);
    }

    private function asAdmin(): AdminToken
    {
        User::factory()->create();
        [$token, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);

        return $token;
    }

    public function test_the_tools_are_only_listed_with_the_token(): void
    {
        ReMomentServer::tool(Tools\AdminTokens::class, ['action' => 'list'])->assertNotRegistered();
        ReMomentServer::tool(Tools\AnalysisStatus::class, ['action' => 'status'])->assertNotRegistered();
        ReMomentServer::tool(Tools\LibrarySettings::class, [])->assertNotRegistered();
    }

    public function test_library_settings_read_and_change(): void
    {
        $this->asAdmin();

        ReMomentServer::tool(Tools\LibrarySettings::class, [])->assertOk()->assertSee('leading_source')->assertSee('"add_played_tracks":false');
        ReMomentServer::tool(Tools\LibrarySettings::class, ['leading_source' => 'files', 'add_played_tracks' => true, 'history_keep_months' => 6])
            ->assertOk()->assertSee('"leading_source":"files"')->assertSee('"add_played_tracks":true')->assertSee('"history_keep_months":6');
        ReMomentServer::tool(Tools\LibrarySettings::class, ['history_keep_months' => 0])->assertOk()->assertSee('"history_keep_months":null');
        ReMomentServer::tool(Tools\LibrarySettings::class, ['leading_source' => 'spotify'])->assertHasErrors();
    }

    public function test_admin_tokens(): void
    {
        $this->asAdmin();
        [$other] = AdminToken::issue('iPad');

        ReMomentServer::tool(Tools\AdminTokens::class, ['action' => 'list'])->assertOk()->assertSee('iPad')->assertDontSee('"token"');
        ReMomentServer::tool(Tools\AdminTokens::class, ['action' => 'revoke', 'id' => $other->id])->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\AdminTokens::class, ['action' => 'revoke', 'id' => $other->id, 'confirm' => true])->assertOk();
        $this->assertDatabaseMissing('admin_tokens', ['id' => $other->id]);
        ReMomentServer::tool(Tools\AdminTokens::class, ['action' => 'revoke_all', 'confirm' => true, 'keep_current' => true])->assertOk()->assertSee('"revoked":0');
        $this->assertDatabaseCount('admin_tokens', 1);
    }

    public function test_analysis_status(): void
    {
        $this->asAdmin();

        ReMomentServer::tool(Tools\AnalysisStatus::class, ['action' => 'status'])->assertOk()->assertSee('stages')->assertSee('schedule');
        ReMomentServer::tool(Tools\AnalysisStatus::class, ['action' => 'problems'])->assertOk()->assertSee('limit');
        ReMomentServer::tool(Tools\AnalysisStatus::class, ['action' => 'run'])->assertOk()->assertSee('queued');
        ReMomentServer::tool(Tools\AnalysisStatus::class, ['action' => 'retry'])->assertHasErrors(['needs file_id']);
        ReMomentServer::tool(Tools\AnalysisStatus::class, ['action' => 'retry', 'file_id' => 99, 'stage' => 'loudness'])->assertHasErrors(['http_404']);
    }
}
