<?php

namespace Tests\Feature;

use App\Domain\Credentials\ServiceCredentials;
use App\Domain\Device\DeviceCache;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\MediaServers\DeviceMedia;
use App\Domain\MediaServers\MediaServers;
use App\Integrations\Jellyfin\JellyfinDriver;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Mcp\Servers\ReMomentServer;
use App\Models\Device;
use App\Models\MediaServer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The media-server layer (workstream 15, docs/architecture/media-servers.md): registry, link to devices, migration, generic API and the Jellyfin wrappers. */
class MediaServersTest extends TestCase
{
    use RefreshDatabase;

    private function jellyfin(): MediaServer
    {
        ServiceCredentials::save('jellyfin', ['api_key' => 'secretkey', 'base_url' => 'http://jf:8096']);
        Cache::flush();

        return MediaServer::where('driver', 'jellyfin')->firstOrFail();
    }

    private function tv(string $ip = '10.0.0.70', string $package = 'org.jellyfin.androidtv'): Device
    {
        $device = Device::factory()->driver(VideoPlayerDriver::class, 'Philips')->create(['ip_address' => $ip]);
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(state: 'playing', source: new Source(name: 'Jellyfin', sourceType: 'APP', connector: $package)));

        return $device;
    }

    private function fakeServer(): void
    {
        Http::fake([
            'jf:8096/Users' => Http::response([['Id' => 'u1']]),
            'jf:8096/Users/u1/Items/Resume*' => Http::response(['Items' => [
                ['Id' => 'm1', 'Name' => 'A Film', 'Type' => 'Movie', 'ProductionYear' => 2020, 'RunTimeTicks' => 1000, 'UserData' => ['PlaybackPositionTicks' => 250]],
            ]]),
            'jf:8096/Shows/NextUp*' => Http::response(['Items' => [
                ['Id' => 'e1', 'Name' => 'Pilot', 'Type' => 'Episode', 'SeriesName' => 'The Show', 'ParentIndexNumber' => 2, 'IndexNumber' => 3],
            ]]),
            'jf:8096/Sessions' => Http::response([
                ['Id' => 'sess1', 'RemoteEndPoint' => '::ffff:10.0.0.70', 'SupportsRemoteControl' => true, 'NowPlayingItem' => ['Id' => 'x', 'Name' => 'On now', 'Type' => 'Movie'], 'PlayState' => ['PositionTicks' => 100_000_000]],
            ]),
            'jf:8096/Sessions/sess1/Playing*' => Http::response('', 204),
        ]);
    }

    // --- registry

    public function test_the_registry_declares_jellyfin_with_its_driver_and_capabilities(): void
    {
        $this->assertSame(JellyfinDriver::class, MediaServers::types()['jellyfin']['driver']);
        $this->assertTrue(MediaServers::isClientPackage('org.jellyfin.androidtv'));
        $this->assertFalse(MediaServers::isClientPackage('com.netflix.ninja'));
        $this->assertFalse(MediaServers::isClientPackage(null));
        $this->assertSame(['continue_watching', 'next_up', 'play_on_client', 'sessions'], MediaServers::capabilities($this->jellyfin()));
    }

    public function test_saving_the_jellyfin_credentials_creates_its_server_once(): void
    {
        $this->assertSame(0, MediaServer::count());
        ServiceCredentials::save('jellyfin', ['base_url' => 'http://jf:8096']); // no key yet: not configured
        $this->assertSame(0, MediaServer::count());

        $server = $this->jellyfin();
        ServiceCredentials::save('jellyfin', ['api_key' => 'another-key-123456']);

        $this->assertSame(1, MediaServer::count());
        $this->assertSame(['jellyfin', 'Jellyfin', 'jellyfin', true, null], [$server->driver, $server->name, $server->credential, $server->enabled, $server->base_url]);
        $this->assertSame('http://jf:8096', $server->baseUrl());
    }

    public function test_a_server_with_its_own_address_overrides_the_credentials(): void
    {
        $server = $this->jellyfin();
        $server->update(['base_url' => 'http://other:8096/']);

        $this->assertSame('http://other:8096', $server->baseUrl());
    }

    // --- the link to devices

    public function test_a_server_applies_to_devices_its_driver_can_play_on(): void
    {
        $server = $this->jellyfin();

        $this->assertSame([$server->id], MediaServers::forDevice($this->tv())->pluck('id')->all());
        $this->assertTrue(MediaServers::forDevice(Device::factory()->create())->isEmpty());
    }

    public function test_a_disabled_or_unconfigured_server_applies_nowhere(): void
    {
        $server = $this->jellyfin();
        $tv = $this->tv();

        $server->update(['enabled' => false]);
        $this->assertTrue(MediaServers::forDevice($tv)->isEmpty());

        $server->update(['enabled' => true]);
        $this->assertCount(1, MediaServers::forDevice($tv));

        Setting::forget('jellyfin_api_key');
        $this->assertTrue(MediaServers::forDevice($tv)->isEmpty());
    }

    public function test_a_device_can_be_limited_to_some_servers_by_its_meta(): void
    {
        $first = $this->jellyfin();
        $second = MediaServer::create(['driver' => 'jellyfin', 'name' => 'Second', 'credential' => 'jellyfin']);
        $tv = $this->tv();

        $this->assertSame([$first->id, $second->id], MediaServers::forDevice($tv)->pluck('id')->all());

        $tv->meta()->create(['key' => MediaServers::DEVICE_META, 'value' => json_encode([$second->id])]);
        $this->assertSame([$second->id], MediaServers::forDevice($tv)->pluck('id')->all());
    }

    // --- the migration of the single setting

    public function test_the_migration_turns_the_existing_setting_into_one_row_and_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_11_21_120100_import_jellyfin_setting_into_media_servers.php');

        $migration->up(); // nothing configured: no row
        $this->assertSame(0, MediaServer::count());

        Setting::set('jellyfin_api_key', \Illuminate\Support\Facades\Crypt::encryptString('secretkey'));
        Setting::set('jellyfin_base_url', 'http://jf:8096');
        $migration->up();
        $migration->up();

        $this->assertSame(1, MediaServer::count());
        $row = MediaServer::first();
        $this->assertSame(['jellyfin', 'Jellyfin', 'jellyfin', true, null], [$row->driver, $row->name, $row->credential, $row->enabled, $row->base_url]);
        $this->assertSame('http://jf:8096', $row->baseUrl());

        $row->update(['name' => 'Living room', 'enabled' => false]);
        $migration->up(); // an edited row is left alone
        $this->assertSame([1, 'Living room', false], [MediaServer::count(), $row->fresh()->name, $row->fresh()->enabled]);

        $migration->down();
        $this->assertSame(1, MediaServer::count());
    }

    // --- the generic API

    public function test_the_servers_are_listed_without_secrets(): void
    {
        $server = $this->jellyfin();

        $response = $this->getJson('/api/media-servers')->assertOk()
            ->assertJsonPath('data.0.id', $server->id)
            ->assertJsonPath('data.0.driver', 'jellyfin')
            ->assertJsonPath('data.0.label', 'Jellyfin')
            ->assertJsonPath('data.0.base_url', 'http://jf:8096')
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.0.configured', true)
            ->assertJsonPath('data.0.capabilities', ['continue_watching', 'next_up', 'play_on_client', 'sessions']);

        $this->assertStringNotContainsString('secretkey', $response->getContent());
    }

    public function test_the_device_media_are_normalised_items_of_its_servers(): void
    {
        $this->fakeServer();
        $server = $this->jellyfin();
        $tv = $this->tv();

        $this->getJson("/api/devices/{$tv->id}/media")->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('servers.0', ['id' => $server->id, 'name' => 'Jellyfin', 'driver' => 'jellyfin', 'ok' => true])
            ->assertJsonPath('continue_watching.0.id', "{$server->id}:m1")
            ->assertJsonPath('continue_watching.0.server_id', $server->id)
            ->assertJsonPath('continue_watching.0.kind', 'movie')
            ->assertJsonPath('continue_watching.0.title', 'A Film')
            ->assertJsonPath('continue_watching.0.progress', 25)
            ->assertJsonPath('next_up.0.kind', 'episode')
            ->assertJsonPath('next_up.0.subtitle', 'S2E3 · Pilot');
    }

    public function test_active_is_false_in_another_app(): void
    {
        $this->fakeServer();
        $this->jellyfin();

        $this->getJson('/api/devices/'.$this->tv(package: 'com.netflix.ninja')->id.'/media')->assertOk()->assertJsonPath('active', false);
    }

    public function test_a_server_that_does_not_answer_is_marked_and_the_others_still_show(): void
    {
        $this->fakeServer();
        $first = $this->jellyfin();
        $second = MediaServer::create(['driver' => 'jellyfin', 'name' => 'Broken', 'credential' => 'jellyfin', 'base_url' => 'http://down:8096']);
        Http::fake(['down:8096/*' => Http::response('', 500)]);

        $response = $this->getJson('/api/devices/'.$this->tv()->id.'/media')->assertOk();

        $response->assertJsonPath('servers.0.ok', true)->assertJsonPath('servers.1.ok', false)->assertJsonPath('servers.1.id', $second->id);
        $this->assertCount(1, $response->json('continue_watching'));
        $this->assertSame($first->id, $response->json('continue_watching.0.server_id'));
    }

    public function test_every_server_failing_is_a_502_and_no_server_a_422(): void
    {
        $this->jellyfin();
        Http::fake(['jf:8096/*' => Http::response('', 500)]);
        $tv = $this->tv();

        $this->getJson("/api/devices/{$tv->id}/media")->assertStatus(502)->assertJsonPath('error', 'driver_error');

        $plain = Device::factory()->create();
        $this->getJson("/api/devices/{$plain->id}/media")->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$plain->id}/media/play", ['item' => '1:m1'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_play_starts_the_item_on_the_device_from_a_position_in_seconds(): void
    {
        $this->fakeServer();
        $server = $this->jellyfin();
        $tv = $this->tv();

        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => "{$server->id}:m1", 'position' => 25.5])->assertOk()->assertJson(['status' => 'ok']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/Sessions/sess1/Playing')
            && str_contains($r->url(), 'itemIds=m1') && str_contains($r->url(), 'startPositionTicks=255000000'));

        // A bare item id works while one server applies, or with `server`.
        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => 'm2'])->assertOk();
        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => 'm3', 'server' => $server->id])->assertOk();
    }

    public function test_play_errors(): void
    {
        $this->fakeServer();
        $first = $this->jellyfin();
        MediaServer::create(['driver' => 'jellyfin', 'name' => 'Second', 'credential' => 'jellyfin']);
        $tv = $this->tv();

        $this->postJson("/api/devices/{$tv->id}/media/play", [])->assertStatus(422)->assertJsonValidationErrors('item');
        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => 'm1', 'position' => -1])->assertStatus(422)->assertJsonValidationErrors('position');
        // Two servers apply: a bare id is ambiguous, a server that does not apply is refused.
        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => 'm1'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => '999:m1'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$tv->id}/media/play", ['item' => "{$first->id}:m1"])->assertOk();
    }

    public function test_a_tv_whose_app_has_no_session_is_a_502(): void
    {
        $server = $this->jellyfin();
        Http::fake(['jf:8096/Sessions' => Http::response([])]);

        $this->postJson('/api/devices/'.$this->tv()->id.'/media/play', ['item' => "{$server->id}:m1"])->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }

    // --- the Jellyfin routes on top

    public function test_the_jellyfin_routes_keep_their_shape_on_the_generic_layer(): void
    {
        $this->fakeServer();
        $this->jellyfin();
        $tv = $this->tv();

        $this->getJson("/api/devices/{$tv->id}/jellyfin")->assertOk()->assertExactJson([
            'active' => true,
            'continue_watching' => [['id' => 'm1', 'title' => 'A Film', 'subtitle' => '2020', 'image' => 'http://jf:8096/Items/m1/Images/Primary?maxWidth=400', 'progress' => 25, 'ticks' => 250]],
            'next_up' => [['id' => 'e1', 'title' => 'The Show', 'subtitle' => 'S2E3 · Pilot', 'image' => 'http://jf:8096/Items/e1/Images/Primary?maxWidth=400', 'progress' => 0, 'ticks' => 0]],
        ]);

        $this->postJson("/api/devices/{$tv->id}/jellyfin/play", ['item_id' => 'm1', 'position_ticks' => 250])->assertOk()->assertExactJson(['status' => 'ok', 'item_id' => 'm1']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Sessions/sess1/Playing') && str_contains($r->url(), 'startPositionTicks=250') && !str_contains($r->url(), 'startPositionTicks=2500'));
    }

    // --- now playing from the servers (the Philips listener's source)

    public function test_the_device_media_report_what_the_servers_say_plays_on_it(): void
    {
        $this->fakeServer();
        $this->jellyfin();

        $playing = DeviceMedia::nowPlaying($this->tv());

        $this->assertSame('On now', $playing->track->name);
        $this->assertSame('jellyfin', $playing->track->source);
        $this->assertSame(10, $playing->position);
        $this->assertNull(DeviceMedia::nowPlaying($this->tv('10.0.0.99')));
        $this->assertNull(DeviceMedia::nowPlaying(Device::factory()->create()));
    }

    // --- MCP

    public function test_the_mcp_tool_lists_servers_and_a_devices_media_and_plays(): void
    {
        $this->fakeServer();
        $server = $this->jellyfin();
        $tv = $this->tv();

        ReMomentServer::tool(\App\Mcp\Tools\MediaServers::class, [])->assertOk()->assertSee('"driver":"jellyfin"');
        ReMomentServer::tool(\App\Mcp\Tools\MediaServers::class, ['device_id' => $tv->id])->assertOk()->assertSee('A Film');
        ReMomentServer::tool(\App\Mcp\Tools\MediaServers::class, ['device_id' => $tv->id, 'item' => "{$server->id}:m1", 'position' => 10])->assertOk()->assertSee('ok');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Sessions/sess1/Playing') && str_contains($r->url(), 'startPositionTicks=100000000'));
    }
}
