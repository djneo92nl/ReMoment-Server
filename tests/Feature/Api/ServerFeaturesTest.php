<?php

namespace Tests\Feature\Api;

use App\Domain\Credentials\ServiceCredentials;
use App\Domain\HomeAssistant\HomeAssistant;
use App\Domain\Push\Vapid;
use App\Models\Client;
use App\Models\MediaServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InfoFeatures;
use Tests\TestCase;

/** `features` of GET /api/info (App\Domain\System\ServerFeatures, docs/api/server-info.md): true only when the server really has it. */
class ServerFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function features(): array
    {
        return $this->getJson('/api/info')->assertOk()->json('features');
    }

    private function node(array $roles, array $enabled): Client
    {
        return Client::create([
            'name' => 'Pi', 'kind' => 'node', 'status' => 'approved', 'hardware_id' => 'n'.uniqid(), 'registration_token' => Client::generateToken(),
            'api_token' => Client::generateToken(), 'roles' => $roles, 'enabled_roles' => $enabled, 'last_seen_at' => now(),
        ]);
    }

    public function test_the_always_present_keys_and_the_baseline_of_the_full_profile(): void
    {
        $features = $this->features();

        $this->assertTrue($features['alerts']);
        $this->assertSame('full', $features['profile']);
        $this->assertIsBool($features['analysis']);
        InfoFeatures::assertFeatures($this->getJson('/api/info'), ['alerts' => true, 'scenes' => true, 'profile' => 'full', 'deep_analysis' => false, 'analysis' => false, 'podcasts' => true]);
        // Nothing switched on: no key at all (a client reads a missing key as false).
        foreach (['media_servers', 'line_in', 'push', 'ha_discovery', 'similar_tracks', 'stems_weights', 'deep_analysis_kinds'] as $key) {
            $this->assertArrayNotHasKey($key, $features, $key);
        }
    }

    public function test_every_key_is_true_or_absent_except_the_documented_ones(): void
    {
        $this->node(['line-in'], ['line-in']);
        Vapid::ensure();
        HomeAssistant::setEnabled(true);

        foreach ($this->features() as $key => $value) {
            if (in_array($key, ['profile', 'deep_analysis_kinds', 'export_formats', 'stems_weights'], true)) {
                continue;
            }
            $this->assertIsBool($value, $key);
            if (!in_array($key, ['analysis', 'deep_analysis'], true)) {
                $this->assertTrue($value, "{$key} is only listed when it is true");
            }
        }
    }

    public function test_the_control_profile_has_no_library_features(): void
    {
        config(['profile.current' => 'control']);

        $features = $this->features();

        $this->assertSame('control', $features['profile']);
        foreach (['discover', 'rip', 'export', 'export_formats', 'sync', 'similar_tracks'] as $key) {
            $this->assertArrayNotHasKey($key, $features, $key);
        }
        // What every profile has.
        foreach (['alerts', 'scenes', 'sleep_timer', 'nodes', 'sub_devices'] as $key) {
            $this->assertTrue($features[$key], $key);
        }
    }

    public function test_the_standard_profile_has_discover_but_not_rip(): void
    {
        config(['profile.current' => 'standard']);

        $features = $this->features();

        $this->assertTrue($features['discover']);
        $this->assertArrayNotHasKey('rip', $features);
        $this->assertFalse($features['analysis']);
    }

    public function test_media_servers_needs_an_enabled_server_with_credentials(): void
    {
        $this->assertArrayNotHasKey('media_servers', $this->features());

        ServiceCredentials::save('jellyfin', ['api_key' => 'secretkey', 'base_url' => 'http://jf:8096']);
        Cache::flush();
        $this->assertTrue($this->features()['media_servers']);

        MediaServer::where('driver', 'jellyfin')->update(['enabled' => false]);
        $this->assertArrayNotHasKey('media_servers', $this->features());
    }

    public function test_line_in_needs_a_node_with_the_role_switched_on(): void
    {
        $this->assertArrayNotHasKey('line_in', $this->features());

        $this->node(['line-in'], []);   // declared, not enabled by the admin
        $this->assertArrayNotHasKey('line_in', $this->features());

        $this->node(['line-in', 'ripper'], ['line-in']);
        $this->assertTrue($this->features()['line_in']);
    }

    public function test_push_needs_the_vapid_pair(): void
    {
        $this->assertArrayNotHasKey('push', $this->features());

        Vapid::ensure();
        $this->assertTrue($this->features()['push']);
    }

    public function test_ha_discovery_follows_the_setting(): void
    {
        $this->assertArrayNotHasKey('ha_discovery', $this->features());

        HomeAssistant::setEnabled(true);
        $this->assertTrue($this->features()['ha_discovery']);

        HomeAssistant::setEnabled(false);
        $this->assertArrayNotHasKey('ha_discovery', $this->features());
    }

    public function test_the_stems_weights_state_is_only_there_with_a_stems_service(): void
    {
        $this->assertArrayNotHasKey('stems_weights', $this->features());
    }
}
