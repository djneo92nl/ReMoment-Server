<?php

namespace Tests\Feature\Mcp;

use App\Domain\Backup\BackupCopy;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools\ServerHealth;
use App\Models\AdminToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The backup copy actions of the `server_health` tool; they only call GET|PUT|POST /api/admin/backup/copy-path. */
class BackupCopyMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backup_copy_actions_need_the_admin_token_and_go_through_the_api(): void
    {
        ReMomentServer::tool(ServerHealth::class, ['action' => 'backup_copy'])->assertNotRegistered();

        User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);
        $dir = sys_get_temp_dir().'/remoment-copy-mcp-'.uniqid();
        mkdir($dir);

        try {
            ReMomentServer::tool(ServerHealth::class, ['action' => 'backup_copy'])->assertOk()->assertSee('last_copy');
            ReMomentServer::tool(ServerHealth::class, ['action' => 'test_backup_copy'])->assertHasErrors(['http_422']);
            ReMomentServer::tool(ServerHealth::class, ['action' => 'test_backup_copy', 'path' => $dir])->assertOk()->assertSee('is writable');
            ReMomentServer::tool(ServerHealth::class, ['action' => 'set_backup_copy_path', 'path' => $dir.'/missing'])->assertHasErrors(['http_422']);
            ReMomentServer::tool(ServerHealth::class, ['action' => 'set_backup_copy_path', 'path' => $dir])->assertOk();
            $this->assertSame($dir, BackupCopy::path());
            ReMomentServer::tool(ServerHealth::class, ['action' => 'set_backup_copy_path', 'path' => ''])->assertOk();
            $this->assertNull(BackupCopy::path());
        } finally {
            @rmdir($dir);
        }
    }
}
