<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Jobs\ProcessArtwork;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\CachesArtwork;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Contract tests for /api/library/playlists* and play-playlist (docs/api/library.md). */
class LibraryPlaylistsApiTest extends TestCase
{
    use CachesArtwork;
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');

        FakeLibraryPlaybackDriver::reset();
        $this->artist = Artist::create(['name' => 'Muse', 'source' => 'dlna']);
    }

    private function album(string $name, ?string $cover = null): Album
    {
        return Album::create([
            'artist_id' => $this->artist->id, 'name' => $name, 'source' => 'dlna',
            'images' => $cover ? [['url' => $cover]] : null,
        ]);
    }

    private function track(?Album $album, string $name, bool $dlna = true, ?string $spotifyId = null): Track
    {
        return $this->makeLibraryTrack($album, $name, $dlna, $spotifyId, $this->artist->id);
    }

    /** @param array<int, Track> $tracks in playlist order */
    private function playlist(string $name, array $tracks, array $attributes = []): Playlist
    {
        $playlist = Playlist::create(['name' => $name, 'source' => 'local'] + $attributes);
        $playlist->tracks()->sync(collect($tracks)->values()->mapWithKeys(fn (Track $t, int $i) => [$t->id => ['position' => $i]])->all());

        return $playlist;
    }

    private function device(string $driver = FakeLibraryPlaybackDriver::class, State $state = State::Standby): Device
    {
        $device = Device::factory()->create([
            'ip_address' => '10.0.0.1',
            'device_driver' => $driver,
        ]);
        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    private function processed(string $url): void
    {
        $this->cacheProcessedArtwork($url, ['proxy_320' => '/storage/a/320.jpg', 'proxy_120' => '/storage/a/120.jpg']);
    }

    private function spotifyApi(): Mockery\MockInterface
    {
        return $this->connectSpotify();
    }

    // --- Browse ---

    public function test_playlists_are_recently_played_first_then_by_name_with_tracks_only(): void
    {
        Queue::fake();
        $t = $this->track($this->album('A'), 'One');
        $this->playlist('beta', [$t]);
        $this->playlist('Alpha', [$t]);
        $this->playlist('Old', [$t], ['last_played_at' => '2026-09-01 10:00:00']);
        $this->playlist('New', [$t], ['last_played_at' => '2026-09-20 10:00:00']);
        $this->playlist('Empty', []);

        $this->getJson('/api/library/playlists')
            ->assertOk()
            ->assertJsonPath('next_cursor', null)
            ->assertJsonPath('data.*.name', ['New', 'Old', 'Alpha', 'beta']);
    }

    public function test_playlist_items_have_count_owner_and_artwork(): void
    {
        Queue::fake();
        $covers = ['https://x.test/1.jpg', 'https://x.test/2.jpg', 'https://x.test/3.jpg', 'https://x.test/4.jpg'];
        $tracks = array_map(fn (string $url) => $this->track($this->album($url, $url), 'T'), $covers);
        $playlist = $this->playlist('Mix', $tracks, ['source' => 'spotify', 'external_id' => 'spotify:playlist:mix']);
        Metadata::create(['metadatable_type' => Playlist::class, 'metadatable_id' => $playlist->id, 'key' => 'spotify_owner', 'value' => 'Remko', 'type' => 'string', 'source' => 'spotify']);

        $composite = PlaylistArtwork::compositeUrl($covers);

        // Not processed yet: null, and the composite is queued once with its covers.
        $this->getJson('/api/library/playlists')
            ->assertJsonPath('data.0', ['id' => $playlist->id, 'name' => 'Mix', 'track_count' => 4, 'owner' => 'Remko', 'artwork' => null]);
        $this->getJson('/api/library/playlists');
        Queue::assertPushed(ProcessArtwork::class, 1);
        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === $composite && $job->sources === $covers);

        $this->processed($composite);
        $this->getJson('/api/library/playlists')
            ->assertJsonPath('data.0.artwork', ['hash' => md5($composite), 'proxy_120' => '/storage/a/120.jpg', 'proxy_320' => '/storage/a/320.jpg']);
    }

    public function test_playlists_are_paged_by_an_opaque_cursor(): void
    {
        Queue::fake();
        $t = $this->track(null, 'One');
        foreach (range(1, 51) as $i) {
            $this->playlist(sprintf('P%02d', $i), [$t]);
        }

        $first = $this->getJson('/api/library/playlists')->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertNotNull($cursor = $first->json('next_cursor'));

        $this->getJson('/api/library/playlists?cursor='.$cursor)
            ->assertJsonPath('data.*.name', ['P51'])
            ->assertJsonPath('next_cursor', null);

        $this->getJson('/api/library/playlists?cursor=nope')->assertStatus(422);
    }

    public function test_playlist_detail_lists_tracks_in_playlist_order(): void
    {
        Queue::fake();
        $album = $this->album('A', 'https://x.test/a.jpg');
        $one = $this->track($album, 'One');
        $two = $this->track($album, 'Two', dlna: false, spotifyId: 'two');
        $three = $this->track(null, 'Three', dlna: false);
        $playlist = $this->playlist('Mix', [$two, $three, $one]);
        $this->processed('https://x.test/a.jpg');

        $this->getJson("/api/library/playlists/{$playlist->id}")
            ->assertOk()
            ->assertExactJson([
                'id' => $playlist->id,
                'name' => 'Mix',
                'owner' => null,
                // Fewer than 4 album covers: the first album's cover.
                'artwork' => ['hash' => md5('https://x.test/a.jpg'), 'proxy_120' => '/storage/a/120.jpg', 'proxy_320' => '/storage/a/320.jpg'],
                // Spotify (Two) and DLNA (One), in the library's order.
                'sources' => ['spotify', 'dlna'],
                'playable' => true,
                'tracks' => [
                    ['id' => $two->id, 'name' => 'Two', 'artist_name' => 'Muse', 'duration' => 200, 'playable' => true],
                    ['id' => $three->id, 'name' => 'Three', 'artist_name' => 'Muse', 'duration' => 200, 'playable' => false],
                    ['id' => $one->id, 'name' => 'One', 'artist_name' => 'Muse', 'duration' => 200, 'playable' => true],
                ],
            ]);

        // On a DLNA-only device the Spotify track isn't playable.
        $device = $this->device();
        $this->getJson("/api/library/playlists/{$playlist->id}?device_id={$device->id}")
            ->assertJsonPath('tracks.*.playable', [false, false, true]);

        $bare = $this->device(FakeBareDriver::class);
        $this->getJson("/api/library/playlists/{$playlist->id}?device_id={$bare->id}")
            ->assertJsonPath('playable', false);

        $this->getJson('/api/library/playlists/999')->assertNotFound();
    }

    public function test_playlist_detail_lists_at_most_200_tracks(): void
    {
        Queue::fake();
        $tracks = array_map(fn (int $i) => $this->track(null, "T{$i}", dlna: false), range(1, 201));
        $playlist = $this->playlist('Long', $tracks);

        $response = $this->getJson("/api/library/playlists/{$playlist->id}")->assertOk();
        $this->assertCount(200, $response->json('tracks'));
        $this->assertSame('T200', $response->json('tracks.199.name'));
    }

    // --- Play ---

    public function test_play_playlist_over_dlna_from_a_start_track_and_records_the_play(): void
    {
        $device = $this->device();
        $a = $this->track(null, 'A');
        $b = $this->track(null, 'B');
        $c = $this->track(null, 'C');
        $playlist = $this->playlist('Mix', [$c, $a, $b]);

        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id, 'start_track_id' => $a->id])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'playlist' => 'Mix']);

        $this->assertSame(['A', 'B'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());
        $this->assertNotNull($playlist->fresh()->last_played_at);

        // The old body still works: the whole playlist in order.
        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id])->assertOk();
        $this->assertSame(['C', 'A', 'B'], FakeLibraryPlaybackDriver::$calls[1]['tracks']->pluck('name')->all());

        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id, 'shuffle' => true])->assertOk();
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], FakeLibraryPlaybackDriver::$calls[2]['tracks']->pluck('name')->all());
    }

    public function test_play_playlist_errors(): void
    {
        $device = $this->device();
        $playlist = $this->playlist('Mix', [$this->track(null, 'No stream', dlna: false)]);
        $other = $this->track(null, 'Elsewhere');

        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id])
            ->assertStatus(422)->assertJsonPath('error', 'not_playable');
        $this->assertNull($playlist->fresh()->last_played_at);

        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id, 'start_track_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('start_track_id');
        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => 999])
            ->assertStatus(422)->assertJsonValidationErrors('playlist_id');

        $gone = $this->device(state: State::Unreachable);
        $this->postJson("/api/devices/{$gone->id}/library/play-playlist", ['playlist_id' => $playlist->id])
            ->assertStatus(503)->assertJsonPath('error', 'unreachable');
    }

    public function test_a_spotify_playlist_plays_as_its_context_from_the_start_track(): void
    {
        $api = $this->spotifyApi();
        $spotify = $this->device(SpotifyDriver::class);
        $one = $this->track(null, 'One', dlna: false, spotifyId: 'one');
        $two = $this->track(null, 'Two', dlna: false, spotifyId: 'two');
        $playlist = $this->playlist('Mix', [$one, $two], ['source' => 'spotify', 'external_id' => 'spotify:playlist:mix']);

        $api->shouldReceive('play')->once()->with('', ['context_uri' => 'spotify:playlist:mix', 'offset' => ['uri' => 'spotify:track:two']])->andReturn(true);
        $api->shouldReceive('shuffle')->once()->with(['state' => true])->andReturn(true);

        $this->postJson("/api/devices/{$spotify->id}/library/play-playlist", [
            'playlist_id' => $playlist->id, 'start_track_id' => $two->id, 'shuffle' => true,
        ])->assertOk()->assertJsonPath('playlist', 'Mix');
    }

    public function test_spotify_failures_are_502(): void
    {
        $api = $this->spotifyApi();
        $spotify = $this->device(SpotifyDriver::class);
        $playlist = $this->playlist('Mix', [$this->track(null, 'One', dlna: false, spotifyId: 'one')], ['source' => 'spotify', 'external_id' => 'spotify:playlist:mix']);

        $api->shouldReceive('play')->andThrow(new \RuntimeException('Premium required'));

        $this->postJson("/api/devices/{$spotify->id}/library/play-playlist", ['playlist_id' => $playlist->id])
            ->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }
}
