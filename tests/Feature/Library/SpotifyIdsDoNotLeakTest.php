<?php

namespace Tests\Feature;

use App\Domain\Library\LibrarySources;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DLNA is a streaming library, never local: a Spotify id kept on a DLNA track must not make it show under Local. */
class SpotifyIdsDoNotLeakTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $name, string $source, ?string $keptSpotifyId = null): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => $source]);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => $source]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => $name, 'source' => $source, 'external_id' => $source.':'.$name]);
        if ($keptSpotifyId) {
            Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'external_id', 'value' => 'spotify:track:'.$keptSpotifyId, 'type' => 'string', 'source' => 'spotify']);
        }

        return $track;
    }

    public function test_a_dlna_track_with_a_kept_spotify_id_is_not_local(): void
    {
        $own = $this->track('Own', 'files');
        $dlna = $this->track('Dlna Merged', 'dlna', 'abc123');
        $plain = $this->track('Dlna Plain', 'dlna');

        $ids = fn (array $hidden) => LibrarySources::tracks(Track::query(), $hidden)->pluck('tracks.id')->sort()->values()->all();

        $this->assertSame([$own->id], $ids(['dlna']), 'Local: own files only');
        $this->assertSame([$dlna->id, $plain->id], $ids(['files']), 'Streaming: DLNA tracks, with or without a Spotify id');
        $this->assertCount(3, $ids([]));
    }

    public function test_a_track_that_has_own_files_and_a_dlna_url_still_shows_in_both(): void
    {
        $both = $this->track('Both', 'dlna');
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $both->id, 'key' => 'dlna_url', 'value' => 'http://x/1.mp3', 'type' => 'url', 'source' => 'files']);

        $this->assertContains($both->id, LibrarySources::tracks(Track::query(), ['dlna'])->pluck('tracks.id')->all());
        $this->assertContains($both->id, LibrarySources::tracks(Track::query(), ['files'])->pluck('tracks.id')->all());
    }

    public function test_the_web_pages_follow_it(): void
    {
        $this->track('Own', 'files');
        $dlna = $this->track('Dlna Merged', 'dlna', 'abc123');

        $this->getJson('/api/library/tracks?scope=local')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['Dlna Merged'], collect($this->getJson('/api/library/tracks?scope=streaming')->json('data'))->pluck('name')->all());
        unset($dlna);
    }
}
