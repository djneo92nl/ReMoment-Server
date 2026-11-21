<?php

namespace Tests\Feature\Api;

use App\Domain\Alerts\Alerts;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Health\ExternalServices;
use App\Domain\Health\Heartbeat;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** GET /api/admin/health and the external-service probes (docs/api/admin-health.md). */
class AdminHealthApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    public function test_every_route_needs_the_admin_token(): void
    {
        $this->assertNeedsAdminToken([['GET', '/api/admin/health'], ['GET', '/api/admin/health/services'], ['POST', '/api/admin/health/services/check']]);
    }

    public function test_the_summary_has_the_shape_the_health_page_shows(): void
    {
        $device = Device::factory()->create(['device_name' => 'Kitchen']);
        DeviceCache::updateState($device->id, State::Playing);
        Cache::put(Heartbeat::SCHEDULER, now()->toIso8601String());

        $response = $this->getJson('/api/admin/health', $this->adminHeaders())->assertOk();

        $response->assertJsonStructure([
            'ok', 'version', 'broker' => ['ok', 'address', 'error', 'ws_url'], 'supervisor' => ['running'],
            'scheduler' => ['ok', 'last_beat_at'], 'queue_worker' => ['ok', 'last_beat_at'], 'queue' => ['pending', 'failed'],
            'backup' => ['ok', 'last_at'], 'upgrade', 'radio_relay',
            'alerts' => ['active', 'warning_or_critical', 'data'],
            'devices' => [['id', 'name', 'state', 'last_seen', 'volume', 'listener' => ['running', 'state', 'since', 'failures', 'last_error', 'retry_at']]],
            'ip_changes',
        ]);
        $response->assertJsonPath('scheduler.ok', true)->assertJsonPath('devices.0.name', 'Kitchen')->assertJsonPath('devices.0.state', 'playing');
        // No broker and no backup in a test: not ok.
        $response->assertJsonPath('ok', false);
    }

    public function test_active_alerts_are_listed_and_count_against_ok(): void
    {
        Alerts::raise('test.kind', 'k', 'critical', 'Something broke', 'Details');

        // A scheduler that never beat raises its own alert while the health is read, so look for ours among them.
        $alerts = $this->getJson('/api/admin/health', $this->adminHeaders())->assertOk()->json('alerts');

        $this->assertContains('Something broke', array_column($alerts['data'], 'title'));
        $this->assertSame(count($alerts['data']), $alerts['active']);
        $this->assertGreaterThanOrEqual(1, $alerts['warning_or_critical']);
    }

    public function test_the_service_probes_are_listed_and_can_be_checked_now(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response([], 200)]);

        $this->getJson('/api/admin/health/services', $this->adminHeaders())->assertOk()->assertJsonPath('data', [])->assertJsonPath('checked_at', null);

        $response = $this->postJson('/api/admin/health/services/check', ['only' => ['musicbrainz']], $this->adminHeaders())->assertOk();
        $response->assertJsonPath('data.0.id', 'musicbrainz')->assertJsonPath('data.0.status', 'ok')->assertJsonStructure(['checked_at', 'problems', 'data' => [['id', 'name', 'group', 'status', 'message']]]);

        // Only the asked probe ran; the list now remembers it.
        $this->assertCount(1, $this->getJson('/api/admin/health/services', $this->adminHeaders())->json('data'));
    }

    public function test_an_unknown_probe_id_is_a_validation_error(): void
    {
        $this->postJson('/api/admin/health/services/check', ['only' => ['nope']], $this->adminHeaders())->assertStatus(422)->assertJsonValidationErrors(['only.0']);
        $this->assertContains('musicbrainz', (new ExternalServices)->probeIds());
    }
}
