<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Alerts\Alerts;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Health\Heartbeat;
use App\Models\AdminToken;
use App\Models\Alert;
use App\Models\Device;
use App\Services\MqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private function topics(string $prefix = 'remoment/alerts/'): array
    {
        return array_values(array_filter($this->mqtt->published, fn ($p) => str_starts_with($p['topic'], $prefix)));
    }

    public function test_raise_is_idempotent_and_publishes_once(): void
    {
        Alerts::raise('queue.stuck', 'heavy', 'critical', 'T', 'M');
        Alerts::raise('queue.stuck', 'heavy', 'critical', 'T', 'M');

        $this->assertSame(1, Alert::count());
        $kind = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === 'remoment/alerts/queue.stuck'));
        $this->assertCount(1, $kind);
        $this->assertFalse($kind[0]['retain']);
        $payload = json_decode($kind[0]['message'], true);
        $this->assertSame(['id', 'kind', 'key', 'severity', 'title', 'message', 'device_id', 'raised_at', 'state'], array_keys($payload));
        $this->assertSame('raised', $payload['state']);

        $summary = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === 'remoment/alerts/active'));
        $this->assertCount(1, $summary);
        $this->assertTrue($summary[0]['retain']);
        $this->assertSame(['queue.stuck'], json_decode($summary[0]['message'], true)['kinds']);
    }

    public function test_a_changed_message_or_severity_republishes(): void
    {
        Alerts::raise('backup.stale', '', 'warning', 'T', 'one');
        Alerts::raise('backup.stale', '', 'warning', 'T', 'two');
        Alerts::raise('backup.stale', '', 'critical', 'T', 'two');

        $this->assertSame(1, Alert::count());
        $this->assertCount(3, array_filter($this->mqtt->published, fn ($p) => $p['topic'] === 'remoment/alerts/backup.stale'));
        $this->assertSame('critical', Alert::first()->severity);
    }

    public function test_keys_dedupe_per_kind_and_resolve_closes_and_reraise_is_new(): void
    {
        $a = Alerts::raise('queue.stuck', 'heavy', 'critical', 'T', 'M');
        Alerts::raise('queue.stuck', 'analysis', 'critical', 'T', 'M');
        $this->assertSame(2, Alert::active()->count());

        $this->assertTrue(Alerts::resolve('queue.stuck', 'heavy'));
        $this->assertFalse(Alerts::resolve('queue.stuck', 'heavy'));
        $this->assertNotNull($a->fresh()->resolved_at);
        $this->assertSame('resolved', json_decode(collect($this->topics())->where('topic', 'remoment/alerts/queue.stuck')->last()['message'], true)['state']);

        $b = Alerts::raise('queue.stuck', 'heavy', 'critical', 'T', 'M');
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(3, Alert::count());
    }

    public function test_prune_keeps_active_and_the_last_100_resolved(): void
    {
        Alerts::raise('backup.stale', '', 'warning', 'T', 'M');
        for ($i = 0; $i < 105; $i++) {
            Alerts::raise('queue.stuck', 'q', 'critical', 'T', 'M');
            Alerts::resolve('queue.stuck', 'q');
        }

        $this->assertSame(5, Alerts::prune());
        $this->assertSame(100, Alert::whereNotNull('resolved_at')->count());
        $this->assertSame(1, Alert::active()->count());
        $this->assertSame(0, Alerts::prune());
    }

    public function test_api_filters_and_cursor(): void
    {
        $a = Alerts::raise('queue.stuck', 'a', 'critical', 'A', 'M');
        $b = Alerts::raise('queue.stuck', 'b', 'critical', 'B', 'M');
        $c = Alerts::raise('backup.stale', '', 'warning', 'C', 'M');
        Alerts::resolve('queue.stuck', 'a');

        $all = $this->getJson('/api/alerts')->assertOk()->json();
        $this->assertSame([$c->id, $b->id, $a->id], array_column($all['data'], 'id'));
        $this->assertSame(['info' => 0, 'warning' => 1, 'critical' => 1], $all['summary']['counts']);

        $this->assertSame([$c->id, $b->id], array_column($this->getJson('/api/alerts?state=active')->json('data'), 'id'));
        $this->assertSame([$a->id], array_column($this->getJson('/api/alerts?state=resolved')->json('data'), 'id'));
        $this->assertSame([$c->id], array_column($this->getJson("/api/alerts?since={$b->id}")->json('data'), 'id'));
        $this->assertCount(1, $this->getJson('/api/alerts?limit=1')->json('data'));
        $this->getJson('/api/alerts?state=bogus')->assertStatus(422);
    }

    public function test_ack_requires_the_admin_token(): void
    {
        $alert = Alerts::raise('backup.stale', '', 'warning', 'T', 'M');

        $this->postJson("/api/admin/alerts/{$alert->id}/ack")->assertStatus(401);
        $this->assertNull($alert->fresh()->acknowledged_at);

        \App\Models\User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        $this->withToken($plain)->postJson("/api/admin/alerts/{$alert->id}/ack")->assertOk()->assertJsonPath('data.id', $alert->id);
        $this->assertNotNull($alert->fresh()->acknowledged_at);
    }

    public function test_a_failing_publish_never_reaches_the_caller(): void
    {
        $this->mock(MqttService::class)->shouldReceive('publish')->andThrow(new \RuntimeException('broker down'));

        $alert = Alerts::raise('backup.stale', '', 'warning', 'T', 'M');

        $this->assertNotNull($alert);
        $this->assertTrue(Alerts::resolve('backup.stale'));
    }

    public function test_an_invalid_severity_is_swallowed(): void
    {
        $this->assertNull(Alerts::raise('backup.stale', '', 'bogus', 'T', 'M'));
        $this->assertSame(0, Alert::count());
    }

    public function test_scheduler_silent_raises_and_resolves(): void
    {
        $checks = new AlertChecks;

        $checks->scheduler();   // never beat
        $this->assertTrue(Alerts::isActive('scheduler.silent'));

        Heartbeat::beat(Heartbeat::SCHEDULER);
        $checks->scheduler();
        $this->assertFalse(Alerts::isActive('scheduler.silent'));
    }

    public function test_queue_stuck_raises_only_for_stuck_and_resolves_on_ok(): void
    {
        $checks = new AlertChecks;

        $checks->queues([['queue' => 'heavy', 'state' => 'busy', 'beat' => null], ['queue' => 'analysis', 'state' => 'unknown', 'beat' => null]]);
        $this->assertSame(0, Alert::count());

        $checks->queues([['queue' => 'heavy', 'state' => 'stuck', 'beat' => now()->subMinutes(9)]]);
        $this->assertTrue(Alerts::isActive('queue.stuck', 'heavy'));

        $checks->queues([['queue' => 'heavy', 'state' => 'busy', 'beat' => null]]);
        $this->assertTrue(Alerts::isActive('queue.stuck', 'heavy'));

        $checks->queues([['queue' => 'heavy', 'state' => 'ok', 'beat' => now()]]);
        $this->assertFalse(Alerts::isActive('queue.stuck', 'heavy'));
    }

    public function test_backup_stale_raises_and_resolves(): void
    {
        $dir = sys_get_temp_dir().'/alerts-backup-'.uniqid();
        mkdir($dir);
        config(['backup.path' => $dir]);
        $checks = new AlertChecks;

        $checks->backup();
        $this->assertTrue(Alerts::isActive('backup.stale'));

        touch($dir.'/remoment-backup-'.now()->format('Ymd-His').'.zip');
        $checks->backup();
        $this->assertFalse(Alerts::isActive('backup.stale'));

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_storage_unreachable_raises_and_resolves(): void
    {
        $dir = sys_get_temp_dir().'/alerts-storage-'.uniqid();
        $location = \App\Models\StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $dir]]);
        $checks = new AlertChecks;

        $checks->storage();
        $this->assertTrue(Alerts::isActive('storage.unreachable', (string) $location->id));

        mkdir($dir);
        Cache::forget('alerts:storage-checked');
        $checks->storage();
        $this->assertFalse(Alerts::isActive('storage.unreachable', (string) $location->id));
        rmdir($dir);
    }

    public function test_device_offline_waits_for_the_threshold_ignores_standby_and_resolves_after_a_grace(): void
    {
        $device = Device::factory()->create();
        $checks = new AlertChecks;
        $start = Carbon::parse('2026-10-27 12:00:00');
        Carbon::setTestNow($start);

        DeviceCache::updateState($device->id, State::Unreachable);
        $checks->devices();
        $this->assertFalse(Alerts::isActive('device.offline', (string) $device->id));

        // A short outage (a listener restart) that ends before 10 minutes never raises.
        Carbon::setTestNow($start->copy()->addMinutes(5));
        DeviceCache::updateState($device->id, State::Playing);
        $checks->devices();
        Carbon::setTestNow($start->copy()->addMinutes(6));
        DeviceCache::updateState($device->id, State::Unreachable);
        $checks->devices();
        Carbon::setTestNow($start->copy()->addMinutes(12));
        $checks->devices();
        $this->assertFalse(Alerts::isActive('device.offline', (string) $device->id));

        // Unreachable for 10 minutes in a row raises.
        Carbon::setTestNow($start->copy()->addMinutes(16));
        $checks->devices();
        $this->assertTrue(Alerts::isActive('device.offline', (string) $device->id));

        // Back up: it stays raised until the grace has passed (debounce), then resolves.
        DeviceCache::updateState($device->id, State::Standby);
        $checks->devices();
        $this->assertTrue(Alerts::isActive('device.offline', (string) $device->id));
        Carbon::setTestNow($start->copy()->addMinutes(19));
        $checks->devices();
        $this->assertFalse(Alerts::isActive('device.offline', (string) $device->id));

        Carbon::setTestNow();
    }

    public function test_standby_never_raises(): void
    {
        $device = Device::factory()->create();
        DeviceCache::updateState($device->id, State::Standby);
        Carbon::setTestNow(now());
        (new AlertChecks)->devices();
        Carbon::setTestNow(now()->addHour());
        (new AlertChecks)->devices();

        $this->assertFalse(Alerts::isActive('device.offline', (string) $device->id));
        Carbon::setTestNow();
    }

    public function test_listener_down_raises_for_a_device_that_should_listen_and_resolves(): void
    {
        config(['devices.listeners' => [\Tests\Support\FakePlayerDriver::class => \Tests\Support\FakeListener::class]]);
        $device = Device::factory()->create();
        $checks = new AlertChecks;
        $start = Carbon::parse('2026-10-27 12:00:00');
        Carbon::setTestNow($start);

        $checks->devices();
        $this->assertFalse(Alerts::isActive('listener.down', (string) $device->id));

        Carbon::setTestNow($start->copy()->addMinutes(4));
        $checks->devices();
        $this->assertTrue(Alerts::isActive('listener.down', (string) $device->id));

        DeviceCache::markListenerAlive($device->id);
        $checks->devices();
        Carbon::setTestNow($start->copy()->addMinutes(7));
        DeviceCache::markListenerAlive($device->id);
        $checks->devices();
        $this->assertFalse(Alerts::isActive('listener.down', (string) $device->id));

        Carbon::setTestNow();
    }

    public function test_a_failing_check_does_not_stop_the_others(): void
    {
        $this->mock(\App\Domain\Health\ExternalServices::class)->shouldReceive('probeStorage')->andThrow(new \RuntimeException('x'));
        \App\Models\StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/nope']]);

        (new AlertChecks)->runAll();

        $this->assertTrue(Alerts::isActive('scheduler.silent'));
        $this->assertTrue(Alerts::isActive('backup.stale'));
    }

    public function test_the_mcp_tool_lists_for_everyone_and_acks_only_for_the_admin(): void
    {
        $alert = Alerts::raise('backup.stale', '', 'warning', 'T', 'M');

        \App\Mcp\Servers\ReMomentServer::tool(\App\Mcp\Tools\Alerts::class, ['state' => 'active'])->assertOk()->assertSee('backup.stale');
        \App\Mcp\Servers\ReMomentServer::tool(\App\Mcp\Tools\Alerts::class, ['action' => 'ack', 'id' => $alert->id])->assertHasErrors(['unauthenticated']);

        \App\Models\User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);
        \App\Mcp\Servers\ReMomentServer::tool(\App\Mcp\Tools\Alerts::class, ['action' => 'ack', 'id' => $alert->id])->assertOk();
        $this->assertNotNull($alert->fresh()->acknowledged_at);
    }

    public function test_the_health_page_shows_active_alerts(): void
    {
        Alerts::raise('queue.stuck', 'heavy', 'critical', 'Queue heavy is stuck', 'Its worker is down.');

        \Livewire\Livewire::test(\App\Livewire\SystemHealth::class)->assertSee('Queue heavy is stuck')->assertSee('Its worker is down.');
    }
}
