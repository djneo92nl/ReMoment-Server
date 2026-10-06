<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\SourceLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Contract test for GET /api/info (see docs/api/server-info.md). */
class InfoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_info_returns_the_bootstrap_shape(): void
    {
        config(['app.name' => 'ReMoment', 'app.version' => '1.4.0', 'mqtt.public_host' => null, 'mqtt.public_port' => 1883]);

        $response = $this->getJson('http://192.168.1.50/api/info')->assertOk();

        $this->assertSame([
            'name' => 'ReMoment',
            'version' => '1.4.0',
            'api_version' => 1,
            'base_url' => 'http://192.168.1.50',
            'api_base_url' => 'http://192.168.1.50/api',
            'artwork_base_url' => 'http://192.168.1.50',
            'mqtt' => [
                'host' => '192.168.1.50',
                'port' => 1883,
                'topic_prefix' => 'remoment/player',
            ],
        ], Arr::except($response->json(), 'placeholder'));
    }

    public function test_placeholder_is_the_music_logo_as_a_full_artwork_object(): void
    {
        $placeholder = $this->getJson('/api/info')->assertOk()->json('placeholder');

        $this->assertSame(['kind', ...ArtworkCache::REQUIRED_KEYS], array_keys($placeholder));
        $this->assertSame('source', $placeholder['kind']);

        $dir = 'artwork/'.md5(SourceLogo::url('music'));
        $this->assertSame(Storage::disk('public')->url("{$dir}/320.jpg"), $placeholder['proxy_320']);
        Storage::disk('public')->assertExists(["{$dir}/320.jpg", "{$dir}/120.jpg", "{$dir}/512.jpg", "{$dir}/bg_1024x600.jpg", "{$dir}/bg_320x480.jpg", "{$dir}/bg_480x480.jpg"]);
        $this->assertNotEmpty($placeholder['colors']);
        $this->assertNotEmpty($placeholder['safe_colors']);
    }

    public function test_a_cached_placeholder_is_reused(): void
    {
        $first = $this->getJson('/api/info')->json('placeholder');
        Storage::disk('public')->delete('artwork/'.md5(SourceLogo::url('music')).'/320.jpg');

        $this->assertSame($first, $this->getJson('/api/info')->json('placeholder'));
        Storage::disk('public')->assertMissing('artwork/'.md5(SourceLogo::url('music')).'/320.jpg');
    }

    public function test_base_url_keeps_a_non_default_port(): void
    {
        $this->getJson('http://192.168.1.50:8080/api/info')
            ->assertJsonPath('base_url', 'http://192.168.1.50:8080')
            ->assertJsonPath('mqtt.host', '192.168.1.50');
    }

    public function test_configured_public_mqtt_host_and_port_win_over_the_request_host(): void
    {
        config(['mqtt.host' => 'mosquitto', 'mqtt.public_host' => 'broker.lan', 'mqtt.public_port' => 11883]);

        $this->getJson('http://192.168.1.50/api/info')
            ->assertJsonPath('mqtt.host', 'broker.lan')
            ->assertJsonPath('mqtt.port', 11883);
    }

    public function test_internal_mqtt_host_is_never_advertised(): void
    {
        config(['mqtt.host' => 'mosquitto', 'mqtt.public_host' => null]);

        $this->getJson('http://remoment.local/api/info')
            ->assertJsonPath('mqtt.host', 'remoment.local');
    }
}
