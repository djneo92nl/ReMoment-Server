<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Jobs\ProcessArtwork;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\CachesArtwork;
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
        $playlist = Playlist::create($attributes + ['name' => $name, 'source' => 'local']);
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
            ->assertJsonPath('data.0', ['id' => $playlist->id, 'name' => 'Mix', 'track_count' => 4, 'owner' => 'Remko', 'last_played_at' => null, 'artwork' => null, 'library' => 'spotify', 'external' => false, 'local_track_count' => 4, 'local_coverage' => 1]);
        $this->getJson('/api/library/playlists');
        Queue::assertPushed(ProcessArtwork::class, 1);
        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === $composite && $job->sources === $covers);

        $this->processed($composite);
        // A processed cover is no library change: the page shows it once its minute of cache is over.
        Cache::forget('library:api-playlists:'.md5(json_encode([[], [], false, 0])));
        $this->getJson('/api/library/playlists')
            ->assertJsonPath('data.0.artwork', ['hash' => md5($composite), 'proxy_120' => '/storage/a/120.jpg', 'proxy_320' => '/storage/a/320.jpg', 'colors' => ['#111111'], 'safe_colors' => ['#999999']]);
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

    public function test_playlist_detail_track_covers_are_the_albums_and_null_when_unprocessed(): void
    {
        Queue::fake();
        $done = $this->track($this->album('Done', 'https://x.test/done.jpg'), 'A');
        $pending = $this->track($this->album('Pending', 'https://x.test/pending.jpg'), 'B');
        $noCover = $this->track($this->album('Bare'), 'C');
        $playlist = $this->playlist('Mix', [$done, $pending, $noCover]);
        $this->cacheProcessedArtwork('https://x.test/done.jpg', ['proxy_120' => '/storage/d/120.jpg', 'proxy_320' => '/storage/d/320.jpg']);

        $tracks = $this->getJson("/api/library/playlists/{$playlist->id}")->assertOk()->json('tracks');

        $this->assertSame(['hash' => md5('https://x.test/done.jpg'), 'proxy_120' => '/storage/d/120.jpg', 'proxy_320' => '/storage/d/320.jpg', 'colors' => ['#111111'], 'safe_colors' => ['#999999']], $tracks[0]['artwork']);
        $this->assertNull($tracks[1]['artwork']);
        $this->assertNull($tracks[2]['artwork']);
    }

    public function test_playlist_detail_resolves_covers_without_a_query_per_track(): void
    {
        Queue::fake();
        $tracks = array_map(fn (int $i) => $this->track($this->album("A{$i}", "https://x.test/{$i}.jpg"), "T{$i}"), range(1, 30));
        $playlist = $this->playlist('Big', $tracks);
        $this->getJson("/api/library/playlists/{$playlist->id}")->assertOk();

        \DB::enableQueryLog();
        $this->getJson("/api/library/playlists/{$playlist->id}")->assertOk();
        $albumQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "albums"') || str_contains($q['query'], 'from `albums`'))->count();

        $this->assertLessThanOrEqual(3, $albumQueries);
    }

    public function test_playlist_detail_has_last_played_at(): void
    {
        $playlist = $this->playlist('Mix', [$this->track(null, 'One')], ['last_played_at' => '2026-10-01 12:00:00']);

        $this->getJson("/api/library/playlists/{$playlist->id}")
            ->assertJsonPath('last_played_at', \Illuminate\Support\Carbon::parse('2026-10-01 12:00:00')->toIso8601String());
    }

    public function test_a_smart_playlist_detail_has_its_rules_and_a_summary(): void
    {
        $genre = \App\Models\Media\Genre::create(['name' => 'Rock', 'slug' => 'rock', 'name_key' => 'rock']);
        $playlist = $this->playlist('Rocky', [$this->track(null, 'One')], ['source' => 'smart', 'rules' => [
            'match' => 'all',
            'rules' => [
                ['field' => 'genre', 'operator' => 'is', 'value' => (string) $genre->id],
                ['field' => 'plays', 'operator' => 'gte', 'value' => 3],
                ['field' => 'bogus', 'operator' => 'is', 'value' => 'x'],
            ],
            'sort' => 'most_played',
            'limit' => 25,
        ]]);

        $this->getJson("/api/library/playlists/{$playlist->id}")
            ->assertOk()
            ->assertJsonPath('smart', true)
            ->assertJsonPath('rules_summary', 'Genre is Rock and Times played at least 3, up to 25 tracks')
            ->assertJsonPath('rules', [
                'match' => 'all',
                'rules' => [
                    ['field' => 'genre', 'operator' => 'is', 'value' => (string) $genre->id, 'text' => 'Genre is Rock'],
                    ['field' => 'plays', 'operator' => 'gte', 'value' => 3, 'text' => 'Times played at least 3'],
                ],
                'sort' => 'most_played',
                'limit' => 25,
            ]);
    }

    public function test_smart_rules_summary_covers_any_units_yes_no_and_no_rules(): void
    {
        $describe = fn (array $rules) => \App\Domain\Library\SmartPlaylist\SmartPlaylistDescriber::summary($rules);

        $this->assertSame('Every track in the library, up to 50 tracks', $describe([]));
        $this->assertSame(
            'Last played not in the last 90 days or Favorite (album or artist): yes, up to 1 track',
            $describe(['match' => 'any', 'limit' => 1, 'rules' => [
                ['field' => 'last_played', 'operator' => 'not_within', 'value' => 90],
                ['field' => 'favorite', 'operator' => 'yes'],
            ]]),
        );
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
            ->assertExactJson(['status' => 'ok', 'playlist' => 'Mix', 'mode' => 'library']);

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

    private function spotifyPlaylist(string $name, int $total, int $local, array $attributes = []): Playlist
    {
        $tracks = [];
        foreach (range(1, $total) as $i) {
            $tracks[] = $this->track(null, "{$name} {$i}", dlna: $i <= $local, spotifyId: Str::slug("{$name}-{$i}"));
        }

        return $this->playlist($name, $tracks, $attributes + ['source' => 'spotify', 'external_id' => 'spotify:playlist:'.Str::slug($name)]);
    }

    public function test_the_local_scope_lists_spotify_playlists_with_enough_local_copies_as_external(): void
    {
        Queue::fake();
        $this->spotifyPlaylist('Half', 4, 2);
        $this->spotifyPlaylist('Almost', 100, 39);
        $this->spotifyPlaylist('Forty', 100, 40);
        $this->spotifyPlaylist('Twofifths', 5, 2);
        $this->spotifyPlaylist('Little', 4, 1);
        $this->spotifyPlaylist('Nothing', 3, 0);
        $this->playlist('Mine', [$this->track(null, 'Mine 1')]);

        // 0.40 is in, 0.39 and below are out; Local playlists are not external.
        $response = $this->getJson('/api/library/playlists?scope=files')->assertOk();
        $this->assertSame(['Forty', 'Half', 'Mine', 'Twofifths'], $response->json('data.*.name'));
        $this->assertSame([true, true, false, true], $response->json('data.*.external'));
        $this->assertSame(['spotify', 'spotify', null, 'spotify'], $response->json('data.*.library'));
        $this->assertSame([40, 2, null, 2], $response->json('data.*.local_track_count'));
        $this->assertSame([100, 4, 1, 5], $response->json('data.*.track_count'));
        $this->assertSame([0.4, 0.5, null, 0.4], $response->json('data.*.local_coverage'));

        // The older alias lists the same.
        $this->assertSame(['Forty', 'Half', 'Mine', 'Twofifths'], $this->getJson('/api/library/playlists?scope=local')->json('data.*.name'));
    }

    public function test_external_playlists_sort_with_the_rest_by_last_played_then_name(): void
    {
        Queue::fake();
        $this->spotifyPlaylist('Zed', 2, 2);
        $this->spotifyPlaylist('Alpha', 2, 2, ['last_played_at' => '2026-09-01 10:00:00']);
        $this->playlist('Beta', [$this->track(null, 'B')]);

        $this->getJson('/api/library/playlists?scope=files')->assertJsonPath('data.*.name', ['Alpha', 'Beta', 'Zed']);
    }

    public function test_local_mode_is_not_playable_without_any_local_copy(): void
    {
        $dlnaDevice = $this->device();
        $playlist = $this->spotifyPlaylist('Mix', 2, 0);

        $this->getJson("/api/library/playlists/{$playlist->id}?library=files&device_id={$dlnaDevice->id}")
            ->assertOk()->assertJsonPath('playable', false)->assertJsonPath('local_coverage', 0);
    }

    public function test_a_library_that_makes_no_sense_for_the_playlist_is_422(): void
    {
        $local = $this->playlist('Mine', [$this->track(null, 'One')]);
        $spotify = $this->spotifyPlaylist('Mix', 2, 2);

        $this->getJson("/api/library/playlists/{$local->id}?library=spotify")->assertStatus(422)->assertJsonValidationErrors('library');
        $this->getJson("/api/library/playlists/{$spotify->id}?library=nope")->assertStatus(422)->assertJsonValidationErrors('library');

        // A local playlist opened from Local is just a library mode playlist.
        $this->getJson("/api/library/playlists/{$local->id}?library=files")->assertOk()->assertJsonPath('mode', 'library')->assertJsonPath('mode_library', 'files');
    }

    public function test_local_mode_queues_the_tracks_with_a_local_copy_in_order_and_skips_the_rest(): void
    {
        $device = $this->device();
        $playlist = $this->spotifyPlaylist('Mix', 5, 0);
        // Tracks 1, 3 and 4 get a local copy; 2 and 5 stay Spotify only.
        $tracks = $playlist->tracks;
        foreach ([0, 2, 3] as $i) {
            Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $tracks[$i]->id, 'key' => 'dlna_url', 'value' => "http://nas.test/{$tracks[$i]->id}.flac", 'type' => 'url', 'source' => 'files']);
        }

        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id, 'library' => 'files'])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'playlist' => 'Mix', 'queued' => 3, 'skipped' => 2, 'mode' => 'local']);

        $this->assertSame(['Mix 1', 'Mix 3', 'Mix 4'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());
        $this->assertNotNull($playlist->fresh()->last_played_at);

        // From a start track: the rest in order; a start track without a copy is refused.
        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id, 'library' => 'files', 'start_track_id' => $tracks[2]->id])
            ->assertOk()->assertJsonPath('queued', 2)->assertJsonPath('skipped', 2);
        $this->assertSame(['Mix 3', 'Mix 4'], FakeLibraryPlaybackDriver::$calls[1]['tracks']->pluck('name')->all());

        $calls = count(FakeLibraryPlaybackDriver::$calls);
        $this->postJson("/api/devices/{$device->id}/library/play-playlist", ['playlist_id' => $playlist->id, 'library' => 'files', 'start_track_id' => $tracks[1]->id])
            ->assertStatus(422)->assertJsonPath('error', 'not_playable');
        $this->assertCount($calls, FakeLibraryPlaybackDriver::$calls);
    }

    public function test_the_playlist_list_counts_local_copies_without_a_query_per_playlist(): void
    {
        Queue::fake();
        foreach (range(1, 5) as $i) {
            $this->spotifyPlaylist("P{$i}", 3, 2);
        }
        $this->getJson('/api/library/playlists?scope=files')->assertOk();

        \DB::enableQueryLog();
        $this->getJson('/api/library/playlists?scope=files')->assertOk();
        $coverage = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'count(distinct'))->count();
        // One for the filter subquery (inside the page query) and one for the page's counts.
        $this->assertLessThanOrEqual(2, $coverage);
        \DB::disableQueryLog();
    }

    public function test_recent_items_say_spotify_for_a_spotify_playlist_with_local_copies(): void
    {
        $one = $this->track(null, 'One', dlna: true, spotifyId: 'one');
        $this->playlist('Mix', [$one], ['external_id' => 'spotify:playlist:mix', 'last_played_at' => now()]);

        $this->getJson('/api/library/recent-items')->assertOk()->assertJsonPath('data.0.library', 'spotify');
    }
}
