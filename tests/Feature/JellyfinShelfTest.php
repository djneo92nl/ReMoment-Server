<?php

namespace Tests\Feature;

use App\Domain\Credentials\ServiceCredentials;
use App\Domain\Device\DeviceCache;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Livewire\JellyfinShelf;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The Jellyfin card of a TV: continue watching and next up while the Jellyfin app plays nothing, and starting an item. */
class JellyfinShelfTest extends TestCase
{
    use RefreshDatabase;

    private function tv(string $package = 'org.jellyfin.androidtv'): Device
    {
        ServiceCredentials::save('jellyfin', ['api_key' => 'secretkey', 'base_url' => 'http://jf:8096']);
        Cache::flush();
        $device = Device::factory()->driver(VideoPlayerDriver::class, 'Philips')->create(['ip_address' => '10.0.0.70']);
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
            'jf:8096/Sessions' => Http::response([['Id' => 'sess1', 'RemoteEndPoint' => '10.0.0.70', 'SupportsRemoteControl' => true]]),
            'jf:8096/Sessions/sess1/Playing*' => Http::response('', 204),
        ]);
    }

    public function test_it_lists_continue_watching_and_next_up_while_jellyfin_plays_nothing(): void
    {
        $this->fakeServer();

        Livewire::test(JellyfinShelf::class, ['device' => $this->tv()])
            ->call('load')
            ->assertSee('Continue watching')
            ->assertSee('A Film')
            ->assertSee('Next up')
            ->assertSee('S2E3 · Pilot');
    }

    public function test_it_is_hidden_in_another_app_or_while_something_plays(): void
    {
        $this->fakeServer();

        Livewire::test(JellyfinShelf::class, ['device' => $this->tv('com.netflix.ninja')])->call('load')->assertDontSee('Continue watching');
    }

    public function test_tapping_a_tile_starts_it_in_the_tvs_session_from_its_position(): void
    {
        $this->fakeServer();

        Livewire::test(JellyfinShelf::class, ['device' => $this->tv()])->call('play', 'm1', 250);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/Sessions/sess1/Playing')
            && str_contains($r->url(), 'playCommand=PlayNow')
            && str_contains($r->url(), 'itemIds=m1')
            && str_contains($r->url(), 'startPositionTicks=250'));
    }

    public function test_the_api_lists_and_plays(): void
    {
        $this->fakeServer();
        $device = $this->tv();

        $this->getJson("/api/devices/{$device->id}/jellyfin")->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('continue_watching.0.title', 'A Film')
            ->assertJsonPath('continue_watching.0.progress', 25)
            ->assertJsonPath('next_up.0.subtitle', 'S2E3 · Pilot');

        $this->postJson("/api/devices/{$device->id}/jellyfin/play", ['item_id' => 'm1', 'position_ticks' => 250])->assertOk()->assertJson(['status' => 'ok']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Sessions/sess1/Playing') && str_contains($r->url(), 'startPositionTicks=250'));
    }

    public function test_the_api_errors(): void
    {
        $this->fakeServer();
        $device = $this->tv();

        $this->postJson("/api/devices/{$device->id}/jellyfin/play", [])->assertStatus(422)->assertJsonValidationErrors('item_id');

        $plain = Device::factory()->create();
        $this->getJson("/api/devices/{$plain->id}/jellyfin")->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_a_tv_whose_app_has_no_session_is_a_502(): void
    {
        Http::fake(['jf:8096/Sessions' => Http::response([])]);
        $device = $this->tv();

        $this->postJson("/api/devices/{$device->id}/jellyfin/play", ['item_id' => 'm1'])->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }

    public function test_the_mcp_tool_lists(): void
    {
        $this->fakeServer();
        $device = $this->tv();

        \App\Mcp\Servers\ReMomentServer::tool(\App\Mcp\Tools\Jellyfin::class, ['device_id' => $device->id])->assertOk()->assertSee('A Film');
    }
}
