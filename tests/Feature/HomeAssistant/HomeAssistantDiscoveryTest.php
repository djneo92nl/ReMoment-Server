<?php

namespace Tests\Feature\HomeAssistant;

use App\Domain\Alerts\Alerts;
use App\Domain\HomeAssistant\DiscoveryPublisher;
use App\Domain\HomeAssistant\HomeAssistant;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Models\Client;
use App\Models\Device;
use App\Models\Play;
use App\Models\Scene;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeAssistantDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function retained(string $topic): ?array
    {
        $hit = collect($this->mqtt->published)->last(fn ($p) => $p['topic'] === $topic);

        return $hit ? ['retain' => $hit['retain'], 'payload' => $hit['message'] === '' ? null : json_decode($hit['message'], true)] : null;
    }

    private function enable(): void
    {
        HomeAssistant::setEnabled(true);
    }

    public function test_hub_sensor_config_has_unique_id_device_availability_and_state_topic(): void
    {
        config(['profile.current' => 'standard']);
        app(DiscoveryPublisher::class)->publishConfigs();

        $c = $this->retained('homeassistant/sensor/remoment_plays_today/config');
        $this->assertTrue($c['retain']);
        $p = $c['payload'];
        $this->assertSame('remoment_plays_today', $p['unique_id']);
        $this->assertSame('remoment/ha/stats', $p['state_topic']);
        $this->assertSame('{{ value_json.plays_today }}', $p['value_template']);
        $this->assertSame(['remoment_server'], $p['device']['identifiers']);
        $this->assertSame('ReMoment Server', $p['device']['name']);
        $this->assertSame([['topic' => 'remoment/ha/status', 'payload_available' => 'online', 'payload_not_available' => 'offline']], $p['availability']);
        $this->assertSame(900, $p['expire_after']);

        $alerts = $this->retained('homeassistant/sensor/remoment_active_alerts/config')['payload'];
        $this->assertSame('remoment/alerts/active', $alerts['state_topic']);
        $this->assertNotNull($this->retained('homeassistant/binary_sensor/remoment_health_scheduler/config'));
        $this->assertSame('problem', $this->retained('homeassistant/binary_sensor/remoment_health_queue/config')['payload']['device_class']);
    }

    public function test_never_a_media_player_or_command_topic(): void
    {
        Device::factory()->create();
        app(DiscoveryPublisher::class)->publishConfigs();

        foreach ($this->mqtt->published as $p) {
            $this->assertStringNotContainsString('/media_player/', $p['topic']);
            $this->assertStringNotContainsString('command_topic', $p['message']);
        }
    }

    public function test_profile_gates_library_and_backlog_entities(): void
    {
        config(['profile.current' => 'control']);
        app(DiscoveryPublisher::class)->publishConfigs();
        $this->assertNull($this->retained('homeassistant/sensor/remoment_library_tracks/config'));
        $this->assertNull($this->retained('homeassistant/sensor/remoment_enrichment_backlog/config'));
        $this->assertNotNull($this->retained('homeassistant/sensor/remoment_plays_total/config'));

        config(['profile.current' => 'full']);
        app(DiscoveryPublisher::class)->publishConfigs();
        $this->assertNotNull($this->retained('homeassistant/sensor/remoment_library_tracks/config'));
        $this->assertNotNull($this->retained('homeassistant/sensor/remoment_analysis_backlog/config'));
    }

    public function test_client_is_a_child_device_and_its_state_is_published(): void
    {
        $client = Client::create(['registration_token' => \Illuminate\Support\Str::random(20), 'name' => 'Wall panel', 'type' => 'single', 'status' => 'approved', 'battery_percent' => 80, 'battery_charging' => true,
            'firmware_version' => '1.2', 'build_number' => '7', 'ip_address' => '10.0.0.5', 'last_seen_at' => now()]);
        $pub = app(DiscoveryPublisher::class);
        $pub->publishConfigs();
        $pub->publishClient($client);

        $c = $this->retained("homeassistant/sensor/remoment_client_{$client->id}_battery/config")['payload'];
        $this->assertSame("remoment_client_{$client->id}_battery", $c['unique_id']);
        $this->assertSame('remoment_server', $c['device']['via_device']);
        $this->assertSame(["remoment_client_{$client->id}"], $c['device']['identifiers']);
        $this->assertSame('battery', $c['device_class']);
        $this->assertSame("remoment/ha/client/{$client->id}", $c['state_topic']);

        $this->assertSame(['online' => true, 'battery' => 80, 'charging' => true, 'firmware' => '1.2', 'build' => '7', 'ip' => '10.0.0.5'],
            $this->retained("remoment/ha/client/{$client->id}")['payload']);

        $n = count($this->mqtt->published);
        $pub->publishClient($client);
        $this->assertCount($n, $this->mqtt->published, 'unchanged state is not repeated');
    }

    public function test_deleted_client_entities_are_removed_with_empty_retained_configs(): void
    {
        $this->enable();
        $client = Client::create(['registration_token' => \Illuminate\Support\Str::random(20), 'name' => 'Panel', 'type' => 'single', 'status' => 'approved']);
        $topic = "homeassistant/sensor/remoment_client_{$client->id}_battery/config";
        $this->assertNotNull($this->retained($topic)['payload']);

        $client->delete();
        $this->assertNull($this->retained($topic)['payload']);
        $this->assertTrue($this->retained($topic)['retain']);
    }

    public function test_source_sensor_per_device_uses_now_playing_not_in_use(): void
    {
        $device = Device::factory()->create(['device_name' => 'Living Room']);
        \App\Domain\Device\DeviceCache::updateNowPlaying($device->id, new NowPlaying(state: 'playing', source: new Source(name: 'TV', sourceType: 'HDMI', connector: '2')));
        \App\Models\DeviceSource::create(['device_id' => $device->id, 'source_id' => 'a', 'friendly_name' => 'TV', 'source_type' => 'MUSIC', 'category' => 'music', 'sort_order' => 1]);
        \App\Models\DeviceSource::create(['device_id' => $device->id, 'source_id' => 'b', 'friendly_name' => 'Hidden', 'source_type' => 'MUSIC', 'category' => 'music', 'hidden' => true]);

        $pub = app(DiscoveryPublisher::class);
        $pub->publishConfigs();
        $pub->publishSources($device);

        $c = $this->retained("homeassistant/sensor/remoment_source_{$device->id}/config")['payload'];
        $this->assertSame('Living Room source', $c['name']);
        $state = $this->retained("remoment/ha/sources/{$device->id}")['payload'];
        $this->assertSame('TV', $state['active']);
        $this->assertSame(['TV'], $state['sources']);
    }

    public function test_stats_state_counts_plays(): void
    {
        config(['profile.current' => 'control']);
        $device = Device::factory()->create();
        foreach ([now(), now(), now()->subDays(3)] as $when) {
            Play::create(['device_id' => $device->id, 'track_name' => 'T', 'artist_name' => 'A', 'source_type' => 'library', 'played_at' => $when]);
        }

        app(DiscoveryPublisher::class)->publishStates();

        $s = $this->retained('remoment/ha/stats')['payload'];
        $this->assertSame(2, $s['plays_today']);
        $this->assertSame(3, $s['plays_7d']);
        $this->assertSame(3, $s['plays_total']);
        $this->assertArrayNotHasKey('library_tracks', $s);
    }

    public function test_health_state_follows_alerts(): void
    {
        Alerts::raise('scheduler.silent', '', 'critical', 'T', 'M');
        app(DiscoveryPublisher::class)->publishStates();

        $h = $this->retained('remoment/ha/health')['payload'];
        $this->assertTrue($h['scheduler']);
        $this->assertFalse($h['queue']);
    }

    public function test_events_are_silent_while_discovery_is_off_and_published_when_on(): void
    {
        Alerts::raise('queue.stuck', 'heavy', 'critical', 'T', 'M');
        $this->assertNull($this->retained('remoment/ha/events/alert'));

        $this->enable();
        Alerts::raise('backup.stale', '', 'warning', 'Backup', 'old');
        Alerts::resolve('backup.stale');

        $events = collect($this->mqtt->published)->where('topic', 'remoment/ha/events/alert')->values();
        $this->assertCount(2, $events);
        $this->assertFalse($events[0]['retain']);
        $first = json_decode($events[0]['message'], true);
        $this->assertSame('raised', $first['event_type']);
        $this->assertSame('backup.stale', $first['kind']);
        $this->assertSame('resolved', json_decode($events[1]['message'], true)['event_type']);

        app(DiscoveryPublisher::class)->publishConfigs();
        $cfg = $this->retained('homeassistant/event/remoment_event_alert/config')['payload'];
        $this->assertSame('remoment/ha/events/alert', $cfg['state_topic']);
        $this->assertSame(['raised', 'resolved'], $cfg['event_types']);
    }

    public function test_scene_client_and_playback_events(): void
    {
        $this->enable();
        $scene = Scene::create(['name' => 'Morning', 'steps' => [], 'stop_on_error' => false]);
        app(\App\Domain\Scenes\SceneRunner::class)->run($scene, 'api');
        $e = $this->retained('remoment/ha/events/scene_activated');
        $this->assertFalse($e['retain']);
        $this->assertSame('activated', $e['payload']['event_type']);
        $this->assertSame('Morning', $e['payload']['scene']);

        Client::create(['registration_token' => \Illuminate\Support\Str::random(20), 'name' => 'New ESP', 'type' => 'single', 'status' => 'pending', 'pairing_code' => 'ABC234']);
        $this->assertSame('pending', $this->retained('remoment/ha/events/client_pending')['payload']['event_type']);
        $this->assertSame(1, $this->retained('remoment/ha/hub')['payload']['pending_clients']);

        $device = Device::factory()->create(['device_name' => 'TV']);
        Cache::flush();
        $np = fn (string $src) => new NowPlaying(track: new TrackData(id: 't1', name: 'Song'), state: 'playing', source: new Source(name: $src));
        event(new NowPlayingUpdated((string) $device->id, $np('Spotify'), 'media'));
        event(new NowPlayingUpdated((string) $device->id, $np('Spotify'), 'media'));
        event(new NowPlayingUpdated((string) $device->id, $np('HDMI'), 'media'));

        $this->assertCount(1, collect($this->mqtt->published)->where('topic', 'remoment/ha/events/track_started'));
        $source = collect($this->mqtt->published)->where('topic', 'remoment/ha/events/source_changed')->values();
        $this->assertCount(1, $source);
        $this->assertSame('HDMI', json_decode($source[0]['message'], true)['source']);
    }

    public function test_command_publishes_and_removes_and_toggle_off_clears(): void
    {
        $this->artisan('ha:discovery')->assertSuccessful();
        $this->assertNotNull($this->retained('homeassistant/sensor/remoment_plays_today/config')['payload']);
        $this->assertSame('online', collect($this->mqtt->published)->last(fn ($p) => $p['topic'] === 'remoment/ha/status')['message']);

        $this->artisan('ha:discovery --remove')->assertSuccessful();
        $this->assertNull($this->retained('homeassistant/sensor/remoment_plays_today/config')['payload']);
        $this->assertTrue($this->retained('homeassistant/sensor/remoment_plays_today/config')['retain']);
        $this->assertSame('offline', collect($this->mqtt->published)->last(fn ($p) => $p['topic'] === 'remoment/ha/status')['message']);
    }

    public function test_settings_toggle_publishes_then_removes_and_honours_prefix(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $this->post(route('settings.mqtt.home-assistant'), ['enabled' => '1', 'prefix' => 'ha'])->assertRedirect();
        $this->assertTrue(HomeAssistant::enabled());
        $this->assertNotNull($this->retained('ha/sensor/remoment_plays_today/config')['payload']);

        $this->post(route('settings.mqtt.home-assistant'), ['prefix' => 'ha'])->assertRedirect();
        $this->assertFalse(HomeAssistant::enabled());
        $this->assertNull($this->retained('ha/sensor/remoment_plays_today/config')['payload']);
        $this->get(route('settings.mqtt'))->assertOk()->assertSee('Home Assistant discovery');
    }

    public function test_scheduler_only_runs_it_when_enabled(): void
    {
        $names = function () {
            $this->app->make(Kernel::class)->call('list');

            return collect(app(Schedule::class)->events())->filter(fn (Event $e) => $e->filtersPass($this->app))->map(fn (Event $e) => (string) $e->description)->all();
        };
        $this->assertNotContains('ha-discovery', $names());
        $this->enable();
        $this->assertContains('ha-discovery', $names());
    }
}
