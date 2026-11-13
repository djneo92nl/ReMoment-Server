<?php

namespace Tests\Feature;

use App\Domain\Manager\ManagerClient;
use App\Domain\Manager\ManagerException;
use App\Livewire\ManagerPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings › Manager: the page and the client of the ReMoment Manager API (docs/architecture/manager.md). */
class ManagerPageTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS = [
        'project' => 'remoment',
        'services' => [
            ['service' => 'app', 'container' => 'remoment-app-1', 'state' => 'running', 'health' => 'unhealthy', 'problem' => null, 'restart_count' => 0, 'up_seconds' => 180, 'memory_bytes' => 459276288, 'memory_usage' => '438MiB / 6GiB', 'cpu_percent' => 5.7],
            ['service' => 'redis', 'container' => 'remoment-redis-1', 'state' => 'running', 'health' => 'healthy', 'problem' => null, 'restart_count' => 0, 'up_seconds' => 90000, 'memory_bytes' => 21000000, 'memory_usage' => '20MiB / 6GiB', 'cpu_percent' => 1.8],
            ['service' => 'analysis', 'container' => 'remoment-analysis-1', 'state' => 'restarting', 'health' => null, 'problem' => 'restarting', 'restart_count' => 4, 'up_seconds' => 5, 'memory_bytes' => 1000, 'memory_usage' => '1KiB / 6GiB', 'cpu_percent' => 0.0],
        ],
        'host' => [
            'disk' => ['path' => '/', 'free_bytes' => 12000000000, 'total_bytes' => 33000000000],
            'memory' => ['total_bytes' => 6442450944, 'free_bytes' => 5153960755],
            'load' => [0.36, 1.51, 1.73],
            'uptime_seconds' => 2678400,
            'cpu_temperature_c' => null,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['manager.url' => 'http://127.0.0.1:8099', 'manager.token_file' => '/nonexistent', 'manager.token' => 'secret-token']);
    }

    private function fakeManager(): void
    {
        Http::fake([
            '127.0.0.1:8099/health' => Http::response(['status' => 'ok', 'version' => '0.1.0']),
            '127.0.0.1:8099/status' => Http::response(self::STATUS),
            '127.0.0.1:8099/audit*' => Http::response(['data' => [['time' => now()->subMinutes(5)->toIso8601String(), 'action' => 'restart', 'service' => 'redis', 'result' => 'ok']]]),
            '127.0.0.1:8099/logs/*' => Http::response("line one\nline two\n", 200, ['Content-Type' => 'text/plain']),
            '127.0.0.1:8099/restart/*' => Http::response(['status' => 'ok', 'action' => 'restart', 'service' => 'redis']),
            '127.0.0.1:8099/restart-all' => Http::response(['status' => 'ok', 'action' => 'restart-all', 'service' => null]),
        ]);
    }

    public function test_the_page_needs_the_admin_login(): void
    {
        $this->get('/settings/manager')->assertRedirect('/login');

        $this->fakeManager();
        $this->actingAs(User::factory()->create())->get('/settings/manager')->assertOk()->assertSee('Manager');
    }

    public function test_the_settings_overview_links_to_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertSee(route('settings.manager'), false);
    }

    public function test_it_lists_the_containers_with_their_problems_and_the_host(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->assertSee('app')
            ->assertSee('unhealthy')
            ->assertSee('438MiB / 6GiB')
            ->assertSeeHtml('data-test="service-redis"')
            ->assertSee('restarting')
            ->assertSee('Disk free')
            ->assertSee('Recent changes')
            ->assertSee('5 minutes ago');
    }

    public function test_every_call_carries_the_token_and_the_change_header(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)->call('restart', 'redis');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/restart/redis')
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r->hasHeader('x-remoment-manager', '1'));
    }

    public function test_restart_and_restart_all_report_the_result(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('restart', 'redis')->assertSee('Restarted redis.')
            ->call('restartAll')->assertSee('Restarted every container.');
    }

    public function test_logs_open_and_close(): void
    {
        $this->fakeManager();

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('showLogs', 'app')->assertSee('line two')
            ->call('showLogs', 'app')->assertDontSee('line two');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/logs/app') && str_contains($r->url(), 'tail=200'));
    }

    public function test_a_busy_manager_is_explained(): void
    {
        Http::fake([
            '127.0.0.1:8099/restart/*' => Http::response(['error' => 'busy'], 409),
            '127.0.0.1:8099/status' => Http::response(self::STATUS),
            '127.0.0.1:8099/audit*' => Http::response(['data' => []]),
        ]);

        Livewire::actingAs(User::factory()->create())->test(ManagerPanel::class)
            ->call('restart', 'redis')->assertSee('busy with another change');
    }

    public function test_an_unreachable_manager_gives_a_page_not_an_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->actingAs(User::factory()->create())->get('/settings/manager')
            ->assertOk()->assertSee('The manager is not available')->assertSee('remoment-manager service running');
    }

    public function test_without_a_token_the_page_says_how_to_get_one(): void
    {
        config(['manager.token' => null]);
        Http::fake(['127.0.0.1:8099/health' => Http::response(['status' => 'ok', 'version' => '0.1.0'])]);

        $this->actingAs(User::factory()->create())->get('/settings/manager')
            ->assertOk()->assertSee('no manager token')->assertSee('It does answer');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/status'));
    }

    public function test_a_refused_token_is_explained(): void
    {
        Http::fake([
            '127.0.0.1:8099/health' => Http::response(['status' => 'ok', 'version' => '0.1.0']),
            '127.0.0.1:8099/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(ManagerException::class);
        $this->expectExceptionMessage('refused the token');

        app(ManagerClient::class)->status();
    }

    public function test_the_token_comes_from_the_file_first(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mgr');
        file_put_contents($file, "from-file\n");
        config(['manager.token_file' => $file]);

        $this->assertSame('from-file', app(ManagerClient::class)->token());

        unlink($file);
        $this->assertSame('secret-token', app(ManagerClient::class)->token());
    }
}
