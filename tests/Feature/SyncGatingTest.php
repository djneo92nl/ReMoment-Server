<?php

namespace Tests\Feature;

use App\Domain\Tools\Tools;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools as McpTools;
use App\Models\AdminToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesSyncLibrary;
use Tests\TestCase;

/** Sync is admin only and Standard/Full only: Control answers 422, the MCP tool needs the admin token and goes through the REST API. */
class SyncGatingTest extends TestCase
{
    use MakesSyncLibrary, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeLibrary();
        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    protected function tearDown(): void
    {
        $this->removeLibrary();
        parent::tearDown();
    }

    public function test_the_control_profile_has_no_sync(): void
    {
        config(['profile.current' => 'control']);

        $auth = ['Authorization' => 'Bearer '.$this->token];
        $this->getJson('/api/admin/sync/targets', $auth)->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->postJson('/api/nodes/sync/plan', [], $auth)->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->getJson('/api/sync/files/x/track:1/y')->assertStatus(422);
        $this->getJson('/api/info')->assertJsonMissingPath('features.sync');
        $this->assertSame([], array_values(array_filter(Tools::available(), fn ($t) => $t['key'] === 'sync')));
    }

    public function test_the_standard_profile_lists_the_sync_page_and_info_feature(): void
    {
        config(['profile.current' => 'standard', 'analysis.ffmpeg' => '/bin/echo']);
        \App\Domain\Analysis\Tools::forget();

        $this->getJson('/api/info')->assertJsonPath('features.sync', true);
        $this->assertContains('sync', array_column(Tools::available(), 'key'));
    }

    public function test_the_mcp_tool_is_admin_only_and_goes_through_the_api(): void
    {
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'list'])->assertNotRegistered();
        request()->headers->set('Authorization', 'Bearer '.$this->token);

        $album = $this->album();
        $this->track($album, 'One');
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'create', 'name' => 'Phone', 'kind' => 'app', 'client_ref' => 'ios-1', 'rules' => ['selections' => [['type' => 'album', 'id' => $album->id]]]])
            ->assertOk()->assertSee('Phone')->assertSee('mp3-320');
        $id = \App\Models\SyncTarget::first()->id;

        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'list'])->assertOk()->assertSee($id);
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'estimate', 'id' => $id])->assertOk()->assertSee('tracks');
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'update', 'id' => $id, 'name' => 'iPhone'])->assertOk()->assertSee('iPhone');
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'run', 'id' => $id])->assertHasErrors(['not_a_node_target']);
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'get'])->assertHasErrors(['id is required']);
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'create', 'name' => 'X', 'kind' => 'app', 'client_ref' => 'ios-2', 'rules' => ['selections' => [['type' => 'genre']]]])->assertHasErrors(['422']);
        ReMomentServer::tool(McpTools\Sync::class, ['action' => 'delete', 'id' => $id])->assertOk();
        $this->assertSame(0, \App\Models\SyncTarget::count());
    }
}
