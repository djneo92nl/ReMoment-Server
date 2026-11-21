<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Alerts\Alerts;
use App\Domain\Manager\ManagerClient;
use App\Domain\Manager\ManagerException;
use App\Livewire\ManagerPanel;
use App\Models\Alert;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings › Manager, NAS mounts card, ManagerClient::mount*() and the mount.unavailable alert (manager/mount.js), with Http::fake. */
class ManagerMountsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'S3cret-Pw!9x';

    private const PLAN = '0123456789abcdef';

    private function mount(array $override = []): array
    {
        return array_replace([
            'name' => 'nas', 'host' => '192.168.1.20', 'share' => 'music', 'user' => 'remko', 'domain' => null, 'writable' => false, 'version' => 'default',
            'path' => '/mnt/remoment/nas', 'unit' => 'mnt-remoment-nas.automount', 'active' => true, 'mounted' => true, 'readable' => true,
            'state' => 'ok', 'error' => null, 'lost' => false, 'last_ok_at' => '2026-11-21T10:00:00Z', 'checked_at' => '2026-11-21T10:05:00Z',
        ], $override);
    }

    private function mounts(array $data, array $extra = []): array
    {
        return array_replace(['dry_run' => false, 'method' => 'systemd', 'base' => '/mnt/remoment', 'problems' => [], 'data' => $data], $extra);
    }

    private function preview(): array
    {
        return [
            'dry_run' => true, 'name' => 'nas', 'updating' => false, 'path' => '/mnt/remoment/nas', 'plan' => self::PLAN,
            'config' => [
                'mount_unit' => "[Mount]\nWhat=//192.168.1.20/music\nOptions=credentials=/etc/remoment-manager/credentials/nas.cred,ro,_netdev,nofail",
                'automount_unit' => "[Automount]\nWhere=/mnt/remoment/nas",
                'fstab' => '//192.168.1.20/music /mnt/remoment/nas cifs ro,_netdev,nofail,x-systemd.automount 0 0',
                'credentials' => "username=remko\npassword=***\n", 'credentials_file' => '/etc/remoment-manager/credentials/nas.cred',
            ],
            'actions' => ['write the credentials file', 'systemctl enable --now mnt-remoment-nas.automount'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['manager.url' => 'http://127.0.0.1:8099', 'manager.token_file' => '/nonexistent', 'manager.token' => 'secret-token']);
    }

    /** A fresh HTTP fake: the first stub that matches wins, so a test that wants other answers starts from scratch. */
    private function fakeManager(array $mounts = [], array $extra = [], array $first = []): void
    {
        Http::swap(new Factory);
        Http::fake($first + [
            '127.0.0.1:8099/health' => Http::response(['status' => 'ok']),
            '127.0.0.1:8099/status' => Http::response(['project' => 'remoment', 'services' => [], 'host' => ['disk' => null, 'memory' => [], 'load' => [], 'uptime_seconds' => 1, 'cpu_temperature_c' => null]]),
            '127.0.0.1:8099/audit*' => Http::response(['data' => []]),
            '127.0.0.1:8099/upgrade*' => Http::response(['running' => false, 'mode' => 'build', 'can_rollback' => false, 'upgrade' => null, 'rollback' => null, 'log' => [], 'log_next' => 0]),
            '127.0.0.1:8099/smb*' => Http::response(['error' => 'not_found'], 404),
            '127.0.0.1:8099/mounts?dry_run=1' => Http::response($this->preview()),
            '127.0.0.1:8099/mounts/*/active*' => fn (Request $r) => Http::response(str_contains($r->url(), 'dry_run=1') ? ['dry_run' => true, 'plan' => 'aaaaaaaaaaaaaaaa'] : ['status' => 'ok', 'name' => 'nas', 'active' => true]),
            '127.0.0.1:8099/mounts/*' => fn (Request $r) => Http::response(str_contains($r->url(), 'dry_run=1') ? ['dry_run' => true, 'plan' => 'bbbbbbbbbbbbbbbb'] : ['status' => 'ok', 'name' => 'nas', 'removed' => true]),
            '127.0.0.1:8099/mounts' => fn (Request $r) => $r->method() === 'POST'
                ? Http::response(['status' => 'ok', 'name' => 'nas', 'path' => '/mnt/remoment/nas', 'updated' => false])
                : Http::response($this->mounts($mounts, $extra)),
        ]);
    }

    private function panel()
    {
        return Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class);
    }

    // --- The client ------------------------------------------------------------------------------------------------

    public function test_the_client_sends_the_dry_run_and_then_the_apply_with_the_plan_token(): void
    {
        $this->fakeManager();
        $client = app(ManagerClient::class);
        $form = ['name' => 'nas', 'host' => '192.168.1.20', 'share' => 'music', 'user' => 'remko', 'password' => self::PASSWORD, 'writable' => false];

        $this->assertSame(self::PLAN, $client->mountPreview($form)['plan']);
        $client->mountAdd($form, self::PLAN);
        $client->mountActive('nas', false);
        $client->mountActive('nas', false, 'aaaaaaaaaaaaaaaa');
        $client->mountRemove('nas');
        $client->mountRemove('nas', 'bbbbbbbbbbbbbbbb');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://127.0.0.1:8099/mounts?dry_run=1' && $r->data() === $form);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://127.0.0.1:8099/mounts' && $r->data() === $form + ['confirm' => self::PLAN]
            && $r->hasHeader('x-remoment-manager', '1') && $r->hasHeader('Authorization', 'Bearer secret-token'));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8099/mounts/nas/active?dry_run=1' && $r->data() === ['active' => false]);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8099/mounts/nas/active' && $r->data() === ['active' => false, 'confirm' => 'aaaaaaaaaaaaaaaa']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://127.0.0.1:8099/mounts/nas?dry_run=1');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://127.0.0.1:8099/mounts/nas?confirm=bbbbbbbbbbbbbbbb');
    }

    public function test_a_409_that_is_not_busy_keeps_the_managers_own_message(): void
    {
        Http::fake(['127.0.0.1:8099/mounts*' => Http::response(['error' => 'mount_busy', 'message' => 'The share is in use and was not unmounted.'], 409)]);

        try {
            app(ManagerClient::class)->mountRemove('nas', self::PLAN);
            $this->fail('expected a ManagerException');
        } catch (ManagerException $e) {
            $this->assertSame('mount_busy', $e->reason);
            $this->assertStringContainsString('in use', $e->getMessage());
        }
    }

    // --- The card ---------------------------------------------------------------------------------------------------

    public function test_the_card_lists_mounts_with_their_state_and_never_a_password(): void
    {
        $this->fakeManager([
            $this->mount(),
            $this->mount(['name' => 'old', 'host' => 'nas2.local', 'share' => 'tapes', 'path' => '/mnt/remoment/old', 'state' => 'not_mounted', 'mounted' => false, 'lost' => true, 'error' => 'The share is not mounted: is the NAS on and reachable?']),
            $this->mount(['name' => 'off', 'host' => 'nas3.local', 'share' => 'x', 'path' => '/mnt/remoment/off', 'state' => 'inactive', 'active' => false, 'mounted' => false, 'readable' => false, 'user' => null, 'writable' => true]),
        ]);

        $this->panel()
            ->assertSeeHtml('data-test="manager-mounts"')
            ->assertSee('//192.168.1.20/music')
            ->assertSee('/mnt/remoment/nas')
            ->assertSee('mounted')
            ->assertSee('it worked before and is gone')
            ->assertSee('is the NAS on and reachable')
            ->assertSeeHtml('data-test="mount-on-off"')       // a switched-off mount can be switched on
            ->assertSeeHtml('data-test="mount-off-nas"')      // a running one can be unmounted
            ->assertSeeHtml('data-test="mount-storage-nas"')  // only a mounted, readable one can become a storage location
            ->assertDontSeeHtml('data-test="mount-storage-old"')
            ->assertDontSeeHtml('data-test="mount-storage-off"')
            ->assertDontSeeHtml('data-test="mount-inbox-nas"') // read-only: no inbox
            ->assertDontSee(self::PASSWORD);
    }

    public function test_an_empty_card_offers_adding_and_a_manager_without_mounts_has_no_card(): void
    {
        $this->fakeManager();
        $this->panel()->assertSeeHtml('data-test="mounts-empty"')->assertSeeHtml('data-test="mount-new"')->assertDontSeeHtml('data-test="mount-form"');

        Http::swap(new Factory);
        Http::fake([
            '127.0.0.1:8099/mounts*' => Http::response(['error' => 'not_found'], 404),
            '127.0.0.1:8099/smb*' => Http::response(['error' => 'not_found'], 404),
            '127.0.0.1:8099/status' => Http::response(['project' => 'remoment', 'services' => [], 'host' => ['disk' => null, 'memory' => [], 'load' => [], 'uptime_seconds' => 1, 'cpu_temperature_c' => null]]),
            '127.0.0.1:8099/audit*' => Http::response(['data' => []]),
            '127.0.0.1:8099/upgrade*' => Http::response(['error' => 'not_found'], 404),
        ]);
        $this->panel()->assertDontSeeHtml('data-test="manager-mounts"')->assertSee('Containers');
    }

    public function test_preview_then_apply_sends_the_previews_plan_token_and_clears_the_password(): void
    {
        $this->fakeManager();

        $component = $this->panel()
            ->call('newMount')
            ->set('mountName', 'nas')->set('mountHost', ' 192.168.1.20 ')->set('mountShare', '/music/')
            ->set('mountUser', 'remko')->set('mountPassword', self::PASSWORD)->set('mountWritable', false)
            ->call('previewMount')
            ->assertSeeHtml('data-test="mount-plan"')
            ->assertSee('x-systemd.automount')
            ->assertSee('password=***')
            ->assertSeeHtml('data-test="mount-apply"');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/mounts?dry_run=1') && $r->data() === [
            'name' => 'nas', 'host' => '192.168.1.20', 'share' => 'music', 'user' => 'remko', 'password' => self::PASSWORD, 'version' => 'default', 'writable' => false,
        ]);
        Http::assertNotSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8099/mounts' && $r->method() === 'POST');

        $component->call('applyMount')
            ->assertSee('was added at /mnt/remoment/nas')
            ->assertSet('mountPassword', '')
            ->assertSet('mountPlan', null)
            ->assertSet('mountForm', false)
            ->assertDontSee(self::PASSWORD);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://127.0.0.1:8099/mounts'
            && ($r->data()['confirm'] ?? null) === self::PLAN && ($r->data()['password'] ?? null) === self::PASSWORD);
    }

    public function test_apply_without_a_preview_is_refused_and_sends_nothing(): void
    {
        $this->fakeManager();

        $this->panel()->call('newMount')->set('mountName', 'nas')->call('applyMount')->assertSee('Preview what will be done first');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_refused_preview_or_apply_shows_the_managers_message_and_forgets_the_password_and_plan(): void
    {
        $this->fakeManager(first: [
            '127.0.0.1:8099/mounts?dry_run=1' => Http::sequence()
                ->push(['error' => 'invalid', 'message' => 'host: a host name or IPv4 address without spaces.'], 422)
                ->push($this->preview()),
            '127.0.0.1:8099/mounts' => fn (Request $r) => $r->method() === 'POST'
                ? Http::response(['error' => 'plan_changed', 'message' => 'The plan changed since the dry run. Run the dry run again.'], 409)
                : Http::response($this->mounts([])),
        ]);

        $this->panel()->call('newMount')->set('mountName', 'nas')->set('mountHost', 'nas lan')->set('mountPassword', self::PASSWORD)
            ->call('previewMount')
            ->assertSee('without spaces')
            ->assertSet('mountPlan', null)
            ->set('mountHost', 'nas.local')
            ->call('previewMount')
            ->assertSeeHtml('data-test="mount-plan"')
            ->call('applyMount')
            ->assertSee('The plan changed since the dry run')
            ->assertSet('mountPlan', null)
            ->assertSet('mountPassword', '')
            ->assertDontSee(self::PASSWORD);
    }

    public function test_cancel_forgets_the_form_and_the_password(): void
    {
        $this->fakeManager();

        $this->panel()->call('newMount')->set('mountName', 'nas')->set('mountPassword', self::PASSWORD)->call('cancelMount')
            ->assertSet('mountForm', false)->assertSet('mountPassword', '')->assertSet('mountName', '');
    }

    public function test_switching_a_mount_off_and_on_runs_the_dry_run_first_and_confirms_with_its_token(): void
    {
        $this->fakeManager([$this->mount()]);

        $this->panel()->call('mountActive', 'nas', false)->assertSee('is off and unmounted');

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/mounts/nas/active'))->map(fn ($pair) => $pair[0]->url())->values()->all();
        $this->assertSame(['http://127.0.0.1:8099/mounts/nas/active?dry_run=1', 'http://127.0.0.1:8099/mounts/nas/active'], $sent);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8099/mounts/nas/active' && $r->data() === ['active' => false, 'confirm' => 'aaaaaaaaaaaaaaaa']);
    }

    public function test_removing_a_mount_is_refused_while_a_storage_location_lives_on_it(): void
    {
        StorageLocation::create(['name' => 'Music NAS', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => '/mnt/remoment/nas/albums']]);
        $this->fakeManager([$this->mount()]);

        $this->panel()->call('removeMount', 'nas')->assertSee('The storage location Music NAS uses this mount');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_removing_a_mount_nothing_uses_dry_runs_then_confirms(): void
    {
        StorageLocation::create(['name' => 'Elsewhere', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => '/mnt/remoment/nas2']]); // a sibling, not on this mount
        $this->fakeManager([$this->mount()]);

        $this->panel()->call('removeMount', 'nas')->assertSee('The mount nas was removed');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://127.0.0.1:8099/mounts/nas?dry_run=1');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://127.0.0.1:8099/mounts/nas?confirm=bbbbbbbbbbbbbbbb');
    }

    // --- Use as a storage location -----------------------------------------------------------------------------------

    public function test_a_working_mount_becomes_a_storage_location_with_the_path_the_manager_gave(): void
    {
        $this->fakeManager([$this->mount(['writable' => true])]);

        $this->panel()->call('useMountAsStorage', 'nas', 'library')->assertSee('Added the storage location NAS nas');

        $location = StorageLocation::firstOrFail();
        $this->assertSame('NAS nas', $location->name);
        $this->assertSame('local', $location->driver);
        $this->assertSame('library', $location->role);
        $this->assertSame(['root' => '/mnt/remoment/nas'], $location->config);

        // A second press does not add it again; the inbox role is allowed on a writable mount.
        $this->panel()->call('useMountAsStorage', 'nas', 'library')->assertSee('exists already');
        $this->panel()->call('useMountAsStorage', 'nas', 'inbox')->assertSee('exists already');
        $this->assertSame(1, StorageLocation::count());
    }

    public function test_the_name_of_a_new_location_never_collides(): void
    {
        StorageLocation::create(['name' => 'NAS nas', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => '/somewhere/else']]);
        $this->fakeManager([$this->mount()]);

        $this->panel()->call('useMountAsStorage', 'nas', 'library');

        $this->assertSame(['NAS nas', 'NAS nas 2'], StorageLocation::orderBy('id')->pluck('name')->all());
    }

    public function test_a_mount_that_is_not_working_a_read_only_inbox_and_an_unknown_mount_add_nothing(): void
    {
        $this->fakeManager([$this->mount(['name' => 'gone', 'state' => 'not_mounted', 'mounted' => false, 'path' => '/mnt/remoment/gone']), $this->mount()]);

        $this->panel()->call('useMountAsStorage', 'gone', 'library')->assertSee('not mounted and readable right now');
        $this->panel()->call('useMountAsStorage', 'nas', 'inbox')->assertSee('An inbox needs a writable mount');
        $this->panel()->call('useMountAsStorage', 'missing', 'library')->assertSee('does not exist');
        $this->panel()->call('useMountAsStorage', 'nas', 'admin')->assertSet('notice', null);

        $this->assertSame(0, StorageLocation::count());
    }

    // --- The alert --------------------------------------------------------------------------------------------------

    public function test_a_lost_mount_raises_a_critical_alert_and_it_resolves_when_the_mount_is_back(): void
    {
        $this->fakeManager([$this->mount(['state' => 'not_mounted', 'mounted' => false, 'lost' => true, 'error' => 'The share is not mounted: is the NAS on and reachable?'])]);

        (new AlertChecks)->mounts();

        $this->assertTrue(Alerts::isActive('mount.unavailable', 'nas'));
        $alert = Alert::where('kind', 'mount.unavailable')->firstOrFail();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('NAS mount nas disappeared', $alert->title);
        $this->assertStringContainsString('//192.168.1.20/music', $alert->message);
        $this->assertStringContainsString('/mnt/remoment/nas', $alert->message);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($alert->toArray()));

        $this->fakeManager([$this->mount()]);
        (new AlertChecks)->mounts();

        $this->assertFalse(Alerts::isActive('mount.unavailable', 'nas'));
    }

    public function test_a_mount_that_never_worked_is_a_warning_and_a_switched_off_one_is_quiet(): void
    {
        $this->fakeManager([
            $this->mount(['name' => 'new', 'state' => 'unreadable', 'readable' => false, 'lost' => false, 'last_ok_at' => null, 'error' => 'The share is mounted but cannot be read.']),
            $this->mount(['name' => 'off', 'state' => 'inactive', 'active' => false, 'mounted' => false, 'lost' => false]),
        ]);

        (new AlertChecks)->mounts();

        $this->assertSame('warning', Alert::where('kind', 'mount.unavailable')->where('key', 'new')->firstOrFail()->severity);
        $this->assertFalse(Alerts::isActive('mount.unavailable', 'off'));
    }

    public function test_a_removed_mount_resolves_its_alert_and_a_silent_manager_changes_nothing(): void
    {
        Alerts::raise('mount.unavailable', 'removed', 'critical', 'T', 'M');
        Alerts::raise('mount.unavailable', 'nas', 'critical', 'T', 'M');

        $this->fakeManager([$this->mount()]);
        (new AlertChecks)->mounts();
        $this->assertFalse(Alerts::isActive('mount.unavailable', 'removed'));
        $this->assertFalse(Alerts::isActive('mount.unavailable', 'nas'));

        Alerts::raise('mount.unavailable', 'nas', 'critical', 'T', 'M');
        Http::swap(new Factory);
        Http::fake(['127.0.0.1:8099/*' => Http::response(['error' => 'down'], 502)]);
        (new AlertChecks)->mounts();
        $this->assertTrue(Alerts::isActive('mount.unavailable', 'nas'), 'no answer means nothing is known: the alert stays');

        config(['manager.token' => '', 'manager.token_file' => '/nonexistent']);
        Http::swap(new Factory);
        Http::fake();
        (new AlertChecks)->mounts();
        Http::assertNothingSent();
        $this->assertTrue(Alerts::isActive('mount.unavailable', 'nas'));
    }

    public function test_runall_includes_the_mount_check_and_survives_a_manager_that_is_not_there(): void
    {
        config(['manager.token' => '', 'manager.token_file' => '/nonexistent']);

        (new AlertChecks)->runAll();

        $this->assertSame(0, Alert::where('kind', 'mount.unavailable')->count());
    }
}
