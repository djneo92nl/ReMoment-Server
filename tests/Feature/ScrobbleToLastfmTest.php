<?php

namespace Tests\Feature;

use App\Jobs\ScrobbleToLastfm;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScrobbleToLastfmTest extends TestCase
{
    use RefreshDatabase;

    private Track $track;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lastfm.api_key' => 'test-key', 'lastfm.shared_secret' => 'test-secret']);
        Setting::set('lastfm_session_key', 'session-key');

        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Test Album', 'source' => 'dlna']);
        $this->track = Track::create([
            'album_id' => $album->id,
            'artist_id' => $artist->id,
            'external_id' => uniqid('dlna:', true),
            'name' => 'Test Track',
            'source' => 'dlna',
            'duration' => 200,
        ]);
        $this->device = Device::factory()->create([
            'ip_address' => '10.0.0.1',
            'device_name' => 'Test Speaker',
            'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
        ]);
    }

    private function makePlay(array $overrides = []): Play
    {
        return Play::create(array_merge([
            'device_id' => $this->device->id,
            'track_id' => $this->track->id,
            'played_at' => now()->subSeconds(200),
            'ended_at' => now(),
            'skipped' => false,
        ], $overrides));
    }

    public function test_scrobbles_when_listened_past_half_the_track(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        (new ScrobbleToLastfm($this->makePlay()))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertSent(fn ($request) => $request['method'] === 'track.scrobble');
    }

    public function test_does_not_scrobble_when_listened_less_than_half_the_track(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        $play = $this->makePlay(['played_at' => now()->subSeconds(50), 'ended_at' => now()]);
        (new ScrobbleToLastfm($play))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertNothingSent();
    }

    public function test_does_not_scrobble_skipped_plays(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        $play = $this->makePlay(['skipped' => true]);
        (new ScrobbleToLastfm($play))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertNothingSent();
    }

    public function test_does_not_scrobble_tracks_of_30_seconds_or_less(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        $this->track->update(['duration' => 30]);
        $play = $this->makePlay(['played_at' => now()->subSeconds(30), 'ended_at' => now()]);
        (new ScrobbleToLastfm($play))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertNothingSent();
    }

    public function test_caps_the_threshold_at_four_minutes_for_long_tracks(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        $this->track->update(['duration' => 1200]);
        $play = $this->makePlay(['played_at' => now()->subSeconds(241), 'ended_at' => now()]);
        (new ScrobbleToLastfm($play))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertSent(fn ($request) => $request['method'] === 'track.scrobble');
    }

    public function test_does_nothing_when_lastfm_is_not_connected(): void
    {
        Setting::forget('lastfm_session_key');
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        (new ScrobbleToLastfm($this->makePlay()))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertNothingSent();
    }

    public function test_does_not_scrobble_plays_still_in_progress(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['scrobbles' => []], 200)]);

        $play = $this->makePlay(['ended_at' => null]);
        (new ScrobbleToLastfm($play))->handle(app(\App\Services\LastfmSessionService::class));

        Http::assertNothingSent();
    }
}
