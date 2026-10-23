<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** What the album screen of a native client needs beyond browsing: track favorites, playlists, Spotify side, source-limited play. */
class AlbumScreenApiTest extends TestCase
{
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
    }

    private function album(): Album
    {
        $artist = Artist::create(['name' => 'Muse', 'source' => 'dlna']);

        return Album::create(['artist_id' => $artist->id, 'name' => 'Absolution', 'source' => 'dlna']);
    }

    public function test_a_track_can_be_favorited_and_shows_in_album_and_favorites(): void
    {
        $album = $this->album();
        $track = $this->makeLibraryTrack($album, 'Hysteria');

        $this->putJson("/api/library/tracks/{$track->id}/favorite", ['favorite' => true])->assertOk()->assertJsonPath('favorite', true);
        $this->putJson("/api/library/tracks/{$track->id}/favorite", ['favorite' => true])->assertOk();

        $this->getJson("/api/library/albums/{$album->id}")->assertJsonPath('tracks.0.favorite', true);
        $this->getJson('/api/library/favorites')
            ->assertJsonPath('tracks.0.id', $track->id)
            ->assertJsonPath('tracks.0.album_name', 'Absolution')
            ->assertJsonPath('tracks.0.artist_name', 'Muse');

        $this->putJson("/api/library/tracks/{$track->id}/favorite", ['favorite' => false])->assertJsonPath('favorite', false);
        $this->getJson('/api/library/favorites')->assertJsonPath('tracks', []);
        $this->putJson("/api/library/tracks/{$track->id}/favorite", [])->assertStatus(422);
    }

    public function test_tracks_and_albums_are_added_to_playlists_made_here(): void
    {
        $album = $this->album();
        $a = $this->makeLibraryTrack($album, 'Hysteria');
        $b = $this->makeLibraryTrack($album, 'Sing for Absolution');

        $id = $this->postJson('/api/library/playlists', ['name' => 'Rock'])->assertCreated()->json('id');
        Playlist::create(['name' => 'From Spotify', 'source' => 'spotify']);

        $this->getJson('/api/library/editable-playlists')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', ['id' => $id, 'name' => 'Rock', 'track_count' => 0, 'track_source' => null]);

        $this->postJson("/api/library/playlists/{$id}/tracks", ['track_id' => $a->id])->assertOk()->assertJsonPath('added', 1);
        $this->postJson("/api/library/playlists/{$id}/tracks", ['album_id' => $album->id])
            ->assertOk()->assertJsonPath('added', 1)->assertJsonPath('track_count', 2);
        $this->assertSame([$a->id, $b->id], Playlist::find($id)->tracks()->pluck('tracks.id')->all());

        $this->postJson("/api/library/playlists/{$id}/tracks", [])->assertStatus(422);
        $this->postJson('/api/library/playlists/'.Playlist::firstWhere('source', 'spotify')->id.'/tracks', ['track_id' => $a->id])
            ->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_a_playlist_holds_one_library(): void
    {
        $album = $this->album();
        $own = $this->makeLibraryTrack($album, 'Hysteria');
        $nas = $this->makeLibraryTrack($album, 'Sing for Absolution', streamSource: 'dlna:nas');

        $id = $this->postJson('/api/library/playlists', ['name' => 'Rock'])->json('id');

        // The first add decides the playlist's library, and an album is added one library at a time.
        $this->postJson("/api/library/playlists/{$id}/tracks", ['album_id' => $album->id])
            ->assertStatus(422)->assertJsonPath('error', 'source_mismatch');
        $this->postJson("/api/library/playlists/{$id}/tracks", ['album_id' => $album->id, 'source' => 'files'])
            ->assertOk()->assertJsonPath('added', 1);
        $this->getJson('/api/library/editable-playlists')->assertJsonPath('data.0.track_source', 'files');

        // Another library is refused, track or album; the same one is fine.
        $this->postJson("/api/library/playlists/{$id}/tracks", ['track_id' => $nas->id])
            ->assertStatus(422)->assertJsonPath('error', 'source_mismatch');
        $this->postJson("/api/library/playlists/{$id}/tracks", ['album_id' => $album->id, 'source' => 'dlna'])
            ->assertStatus(422)->assertJsonPath('error', 'source_mismatch');
        $this->postJson("/api/library/playlists/{$id}/tracks", ['track_id' => $own->id, 'source' => 'dlna'])
            ->assertStatus(422)->assertJsonPath('error', 'source_mismatch');
        $this->postJson("/api/library/playlists/{$id}/tracks", ['track_id' => $own->id, 'source' => 'files'])
            ->assertOk()->assertJsonPath('added', 0);

        $this->assertSame([$own->id], Playlist::find($id)->tracks()->pluck('tracks.id')->all());
    }

    public function test_spotify_side_marks_tracks_the_library_holds_and_can_be_filled(): void
    {
        Queue::fake();
        $album = $this->album();
        $held = $this->makeLibraryTrack($album, 'Hysteria');

        $this->getJson("/api/library/albums/{$album->id}/spotify")->assertStatus(422)->assertJsonPath('error', 'spotify_unavailable');

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->andReturn(['albums' => ['items' => [['id' => 'alb1', 'name' => 'Absolution', 'artists' => [['name' => 'Muse']]]]]]);
        $api->shouldReceive('getAlbumTracks')->andReturn(['items' => [
            ['id' => 't2', 'name' => 'Sing for Absolution', 'track_number' => 2, 'disc_number' => 1, 'duration_ms' => 300000],
            ['id' => 't1', 'name' => 'Hysteria', 'track_number' => 1, 'disc_number' => 1, 'duration_ms' => 227000],
        ], 'next' => null]);
        $this->connectSpotify($api);

        $this->getJson("/api/library/albums/{$album->id}/spotify")
            ->assertOk()
            ->assertJsonPath('missing', 1)
            ->assertJsonPath('tracks.0', ['spotify_id' => 't1', 'name' => 'Hysteria', 'number' => 1, 'disc' => 1, 'duration' => 227, 'track_id' => $held->id])
            ->assertJsonPath('tracks.1.track_id', null);
    }

    public function test_play_album_can_be_limited_to_a_source_and_a_spotify_track_played_by_id(): void
    {
        $album = $this->album();
        $this->makeLibraryTrack($album, 'Hysteria');
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        DeviceCache::updateState($device->id, State::Standby);

        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id, 'source' => 'dlna'])->assertOk();
        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id, 'source' => 'tidal'])->assertStatus(422);
        $this->postJson("/api/devices/{$device->id}/library/play", [])->assertStatus(422);
        $this->postJson("/api/devices/{$device->id}/library/play", ['spotify_track_id' => 'abc123'])
            ->assertStatus(422)->assertJsonPath('error', 'not_playable');
    }
}
