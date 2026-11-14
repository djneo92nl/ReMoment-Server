<?php

namespace Tests\Feature;

use App\Domain\Manager\ManagerClient;
use App\Domain\Manager\ManagerException;
use App\Livewire\ManagerPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings › Manager, Network shares card: the app's side of the manager's SMB endpoints (manager/smb.js), with Http::fake. */
class ManagerSmbTest extends TestCase
{
    use RefreshDatabase;

    private function smb(array $override = []): array
    {
        return array_replace([
            'dry_run' => false, 'running' => true, 'service' => 'smbd', 'include_present' => true, 'conf_file' => '/etc/samba/remoment.conf', 'problems' => [],
            'user' => ['name' => 'remoment-smb', 'created_at' => '2026-11-14T10:00:00Z', 'password_set_at' => '2026-11-14T10:00:00Z'],
            'sessions' => ['count' => 2, 'by_share' => ['inbox' => 2], 'data' => []],
            'shares' => [
                ['name' => 'library', 'label' => 'Music library', 'note' => 'Read-only unless writing is on.', 'enabled' => true, 'guest' => false, 'writable' => false, 'can_set_writable' => true, 'path' => '/srv/library', 'path_error' => null, 'path_exists' => true],
                ['name' => 'exports', 'label' => 'Exports', 'note' => 'Finished exports.', 'enabled' => true, 'guest' => true, 'writable' => false, 'can_set_writable' => false, 'path' => '/srv/exports', 'path_error' => null, 'path_exists' => false],
                ['name' => 'inbox', 'label' => 'Inbox (drop directory)', 'note' => 'Drop here.', 'enabled' => false, 'guest' => false, 'writable' => true, 'can_set_writable' => false, 'path' => '/srv/inbox', 'path_error' => null, 'path_exists' => true],
            ],
        ], $override);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['manager.url' => 'http://127.0.0.1:8099', 'manager.token_file' => '/nonexistent', 'manager.token' => 'secret-token']);
    }

    private function fakeManager(array $smb = []): void
    {
        Http::fake([
            '127.0.0.1:8099/health' => Http::response(['status' => 'ok']),
            '127.0.0.1:8099/status' => Http::response(['project' => 'remoment', 'services' => [], 'host' => ['disk' => null, 'memory' => [], 'load' => [], 'uptime_seconds' => 1, 'cpu_temperature_c' => null]]),
            '127.0.0.1:8099/audit*' => Http::response(['data' => []]),
            '127.0.0.1:8099/upgrade*' => Http::response(['running' => false, 'mode' => 'build', 'can_rollback' => false, 'upgrade' => null, 'rollback' => null, 'log' => [], 'log_next' => 0]),
            '127.0.0.1:8099/smb/config' => Http::response(['config' => "[library]\n"]),
            '127.0.0.1:8099/smb/shares/*' => fn (Request $r) => Http::response(str_contains($r->url(), 'dry_run=1')
                ? ['dry_run' => true, 'share' => 'inbox', 'enabled' => true, 'writable' => true, 'config' => "[inbox]\n   guest ok = no\n", 'actions' => ['write']]
                : ['status' => 'ok', 'share' => 'inbox', 'enabled' => true, 'writable' => true]),
            '127.0.0.1:8099/smb/user*' => Http::response(['status' => 'ok', 'user' => 'remoment-smb', 'created' => true, 'password' => 'Abcdefghjkmnpqrstuvwx234']),
            '127.0.0.1:8099/smb' => Http::response($this->smb($smb)),
        ]);
    }

    public function test_the_card_lists_the_three_shares_and_the_user_without_any_password(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->assertSeeHtml('data-test="manager-smb"')
            ->assertSee('Music library')
            ->assertSee('Exports')
            ->assertSee('Inbox (drop directory)')
            ->assertSee('/srv/library')
            ->assertSee('folder missing')
            ->assertSee('remoment-smb')
            ->assertSee('2 connected')
            ->assertDontSeeHtml('data-test="smb-password"');
    }

    public function test_it_warns_about_a_missing_include_and_dry_run_mode(): void
    {
        $this->fakeManager(['include_present' => false, 'dry_run' => true, 'user' => null, 'problems' => ['REMOMENT_SMB_USER is not a valid unix user name.']]);

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->assertSeeHtml('data-test="smb-include"')
            ->assertSee('dry-run mode')
            ->assertSee('none yet')
            ->assertSee('not a valid unix user name');
    }

    public function test_guest_access_is_shown_and_switched_per_share(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->assertSeeHtml('data-test="smb-guest-on-exports"')
            ->assertDontSeeHtml('data-test="smb-guest-on-library"')
            ->call('smbGuest', 'library', true)
            ->assertSee('open to guests');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/smb/shares/library') && $r->data() === ['enabled' => true, 'guest' => true]);
    }

    public function test_a_manager_without_smb_just_has_no_card(): void
    {
        Http::fake([
            '127.0.0.1:8099/smb*' => Http::response(['error' => 'not_found'], 404),
            '127.0.0.1:8099/status' => Http::response(['project' => 'remoment', 'services' => [], 'host' => ['disk' => null, 'memory' => [], 'load' => [], 'uptime_seconds' => 1, 'cpu_temperature_c' => null]]),
            '127.0.0.1:8099/audit*' => Http::response(['data' => []]),
            '127.0.0.1:8099/upgrade*' => Http::response(['error' => 'not_found'], 404),
        ]);

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)->assertDontSeeHtml('data-test="manager-smb"')->assertSee('Containers');
    }

    public function test_switching_a_share_on_sends_the_fixed_body_with_token_and_header(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('smbShare', 'inbox', true)
            ->assertSee('The inbox share is on.');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://127.0.0.1:8099/smb/shares/inbox'
            && $r->data() === ['enabled' => true] && $r->hasHeader('x-remoment-manager', '1') && $r->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_the_library_writing_switch_is_sent(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)->call('smbShare', 'library', true, true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/smb/shares/library') && $r->data() === ['enabled' => true, 'writable' => true]);
    }

    public function test_preview_asks_for_a_dry_run_and_shows_the_config(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('smbPreviewShare', 'inbox', true)
            ->assertSeeHtml('data-test="smb-preview"')
            ->assertSee('guest ok = no')
            ->call('closeSmbPreview')
            ->assertDontSeeHtml('data-test="smb-preview"');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/smb/shares/inbox?dry_run=1'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/smb/shares/inbox'));
    }

    public function test_the_password_is_shown_once_and_dropped_when_dismissed(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('createShareUser')
            ->assertSeeHtml('data-test="smb-password"')
            ->assertSee('Abcdefghjkmnpqrstuvwx234')
            ->call('dismissSharePassword')
            ->assertDontSee('Abcdefghjkmnpqrstuvwx234')
            ->assertSet('sharePassword', null);
    }

    public function test_a_refusal_from_the_manager_is_explained(): void
    {
        Http::fake([
            '127.0.0.1:8099/smb/shares/*' => Http::response(['error' => 'no_user', 'message' => 'Create the share user first.'], 422),
            '127.0.0.1:8099/*' => Http::response([], 404),
        ]);

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('smbShare', 'inbox', true)
            ->assertSeeHtml('data-test="manager-failure"')
            ->assertSee('Create the share user first.');
    }

    public function test_the_client_methods_use_the_right_verbs_and_paths(): void
    {
        $this->fakeManager();
        $client = app(ManagerClient::class);

        $this->assertSame('remoment-smb', $client->smb()['user']['name']);
        $this->assertSame("[library]\n", $client->smbConfig());
        $client->smbCreateUser(dryRun: true);
        $client->smbRemoveUser();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://127.0.0.1:8099/smb/user?dry_run=1');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://127.0.0.1:8099/smb/user');
    }

    public function test_the_client_needs_a_token(): void
    {
        config(['manager.token' => null]);
        $this->expectException(ManagerException::class);
        app(ManagerClient::class)->smb();
    }
}
