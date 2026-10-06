<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A client's hidden_sources filter on /api/library/* (docs/api/library.md, "Hiding sources"). */
class LibrarySourceFilterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
    }

    private function client(array $hidden): Client
    {
        return Client::create([
            'name' => 'Kitchen', 'type' => 'multi', 'status' => 'approved',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(), 'hidden_sources' => $hidden,
        ]);
    }

    /** An artist with one album of one track from $source. */
    private function release(string $name, string $source): Track
    {
        $artist = Artist::create(['name' => $name, 'source' => $source]);
        $album = Album::create(['artist_id' => $artist->id, 'name' => "{$name} LP", 'source' => $source]);

        return Track::create([
            'album_id' => $album->id, 'artist_id' => $artist->id, 'name' => "{$name} song",
            'external_id' => "{$source}:{$name}", 'source' => $source,
        ]);
    }

    public function test_no_client_sees_everything(): void
    {
        $this->release('Dlna Band', 'dlna');
        $this->release('Spot Band', 'spotify');

        $this->getJson('/api/library/artists')->assertJsonCount(2, 'data');
    }

    public function test_hidden_source_leaves_out_its_artists_albums_and_recent(): void
    {
        $this->release('Dlna Band', 'dlna');
        $this->release('Spot Band', 'spotify');
        $client = $this->client(['spotify']);

        $this->getJson("/api/library/artists?client={$client->api_token}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dlna Band');

        $spotify = Artist::where('name', 'Spot Band')->first();
        $this->getJson("/api/library/artists/{$spotify->id}?client={$client->api_token}")
            ->assertJsonCount(0, 'albums');
    }

    public function test_merged_track_stays_while_one_of_its_sources_is_shown(): void
    {
        $track = $this->release('Both Band', 'dlna');
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $track->id,
            'key' => 'external_id', 'value' => 'spotify:track:abc', 'type' => 'string', 'source' => 'spotify',
        ]);

        $this->getJson('/api/library/artists?client='.$this->client(['dlna'])->api_token)->assertJsonCount(1, 'data');
        $this->getJson('/api/library/artists?client='.$this->client(['dlna', 'spotify'])->api_token)->assertJsonCount(0, 'data');
    }

    public function test_dlna_metadata_source_with_server_suffix_is_hidden(): void
    {
        $track = $this->release('Nas Band', 'spotify');
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $track->id,
            'key' => 'dlna_url', 'value' => 'http://nas.test/1.flac', 'type' => 'url', 'source' => 'dlna:7',
        ]);

        // Still available from Spotify, so hiding DLNA alone keeps it; hiding both removes it.
        $this->getJson('/api/library/artists?client='.$this->client(['dlna'])->api_token)->assertJsonCount(1, 'data');
        $this->getJson('/api/library/artists?client='.$this->client(['spotify', 'dlna'])->api_token)->assertJsonCount(0, 'data');
    }

    public function test_album_and_playlist_tracks_are_filtered(): void
    {
        $dlna = $this->release('Dlna Band', 'dlna');
        $spotify = $this->release('Spot Band', 'spotify');
        $playlist = Playlist::create(['name' => 'Mix', 'source' => 'local']);
        $playlist->tracks()->attach([$dlna->id => ['position' => 1], $spotify->id => ['position' => 2]]);
        $client = $this->client(['spotify']);

        $this->getJson("/api/library/playlists/{$playlist->id}?client={$client->api_token}")
            ->assertJsonCount(1, 'tracks');
        $this->getJson("/api/library/playlists?client={$client->api_token}")
            ->assertJsonPath('data.0.track_count', 1);

        $this->getJson("/api/library/albums/{$spotify->album_id}?client={$client->api_token}")
            ->assertJsonCount(0, 'tracks');
    }

    public function test_unknown_client_is_404(): void
    {
        $this->getJson('/api/library/artists?client=nope')->assertNotFound();
    }
}
