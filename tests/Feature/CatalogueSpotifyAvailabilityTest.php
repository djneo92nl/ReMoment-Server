<?php

namespace Tests\Feature;

use App\Domain\Catalogue\CatalogueLinker;
use App\Domain\Catalogue\SpotifyAvailability;
use App\Domain\Library\Enrichment;
use App\Jobs\ImportSpotifyAlbumById;
use App\Jobs\LookupSpotifyAvailability;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** Spotify availability of an artist's MusicBrainz release-groups, lazy lookups, and adding an album Spotify has. */
class CatalogueSpotifyAvailabilityTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        Enrichment::save($this->artist, 'spotify_id', 'sp-artist', 'string', Enrichment::SPOTIFY);
    }

    private function row(string $title, int $year, array $extra = []): CatalogueAlbum
    {
        return CatalogueAlbum::create($extra + ['artist_id' => $this->artist->id, 'release_group_mbid' => 'rg-'.md5($title), 'title' => $title, 'type' => 'album', 'first_release_year' => $year, 'seen_at' => now()]);
    }

    private function spotifyHas(array $albums): SpotifyWebAPI
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getArtistAlbums')->andReturn(['items' => array_map(fn ($a) => [
            'id' => $a[0], 'name' => $a[1], 'release_date' => $a[2], 'artists' => [['name' => 'Band']], 'images' => [],
        ], $albums), 'next' => null]);

        return $this->connectSpotify($api);
    }

    public function test_one_listing_answers_every_release_group_of_the_artist(): void
    {
        $this->spotifyHas([['a1', 'First Album', '1999-05-01'], ['a2', 'Second Album (Deluxe Edition)', '2010-01-01'], ['a3', 'Third', '1990-01-01']]);
        $first = $this->row('First Album', 1999);
        $second = $this->row('Second Album', 2008);
        $absent = $this->row('Not On Spotify', 2005);
        $third = $this->row('Third', 2015);

        $written = app(SpotifyAvailability::class)->lookupArtist($this->artist);

        $this->assertSame(4, $written);
        $answer = fn (CatalogueAlbum $r) => AlbumAvailability::where('catalogue_album_id', $r->id)->first();
        $this->assertSame(['available', 'spotify:album:a1', 90], [$answer($first)->status, $answer($first)->external_id, $answer($first)->confidence]);
        $this->assertSame(75, $answer($second)->confidence, 'equal name once the edition is ignored, other year');
        $this->assertSame('unavailable', $answer($absent)->status);
        $this->assertNull($answer($absent)->external_id);
        $this->assertSame(75, $answer($third)->confidence);
    }

    public function test_a_fresh_answer_is_not_asked_again_but_a_wished_one_is_rechecked_weekly(): void
    {
        $api = $this->spotifyHas([['a1', 'First Album', '1999']]);
        $row = $this->row('First Album', 1999);
        $lookups = app(SpotifyAvailability::class);

        $lookups->lookupArtist($this->artist);
        $this->assertSame(0, $lookups->lookupArtist($this->artist), 'answered just now');

        AlbumAvailability::query()->update(['checked_at' => now()->subDays(10)]);
        $this->assertCount(0, $lookups->staleRows($this->artist), 'a found answer lasts 180 days');
        $row->update(['wanted_at' => now()]);
        $this->assertCount(1, $lookups->staleRows($this->artist), 'a wished album is rechecked after a week');
        unset($api);
    }

    public function test_albums_saved_from_spotify_need_no_lookup_and_ignored_ones_are_skipped(): void
    {
        $this->spotifyHas([]);
        $saved = Album::create(['artist_id' => $this->artist->id, 'name' => 'Saved', 'source' => 'spotify']);
        Track::create(['album_id' => $saved->id, 'artist_id' => $this->artist->id, 'name' => 'T', 'source' => 'spotify', 'external_id' => 'spotify:track:1']);
        $this->row('Saved', 2000);
        $this->row('Ignored', 2001, ['ignored_at' => now()]);
        CatalogueLinker::linkArtist($this->artist);

        $this->assertCount(0, app(SpotifyAvailability::class)->staleRows($this->artist));
    }

    public function test_without_spotify_nothing_is_queued_or_written(): void
    {
        Queue::fake();
        $this->disconnectSpotify();
        $this->row('First Album', 1999);

        LookupSpotifyAvailability::queueFor($this->artist);
        $this->assertSame(0, app(SpotifyAvailability::class)->lookupArtist($this->artist));

        Queue::assertNothingPushed();
        $this->assertSame(0, AlbumAvailability::count());
    }

    public function test_viewing_the_discography_queues_one_lookup_an_hour(): void
    {
        Queue::fake();
        $this->spotifyHas([]);
        $this->row('First Album', 1999);

        $this->getJson("/api/library/artists/{$this->artist->id}/discography")->assertOk();
        $this->getJson("/api/library/artists/{$this->artist->id}/discography")->assertOk();

        Queue::assertPushedOn('catalogue', LookupSpotifyAvailability::class);
        Queue::assertPushed(LookupSpotifyAvailability::class, 1);
    }

    public function test_the_answer_makes_the_entry_streamable_and_can_be_added(): void
    {
        Queue::fake();
        $this->spotifyHas([['a1', 'First Album', '1999']]);
        $row = $this->row('First Album', 1999);
        app(SpotifyAvailability::class)->lookupArtist($this->artist);

        $item = $this->getJson("/api/library/catalogue/{$row->id}")->json();
        $this->assertSame('streamable', $item['state']);
        $this->assertNull($item['album_id']);

        $this->postJson("/api/library/catalogue/{$row->id}/import")->assertStatus(202)->assertJson(['status' => 'queued']);
        Queue::assertPushed(ImportSpotifyAlbumById::class, fn ($job) => $job->spotifyAlbumId === 'a1');
    }

    public function test_only_an_album_spotify_has_can_be_added(): void
    {
        Queue::fake();
        $this->spotifyHas([]);
        $unknown = $this->row('Nothing', 1999);
        $linked = $this->row('Mine', 2000);
        $album = Album::create(['artist_id' => $this->artist->id, 'name' => 'Mine', 'source' => 'files']);
        CatalogueLinker::link($album);

        $this->postJson("/api/library/catalogue/{$unknown->id}/import")->assertStatus(422)->assertJson(['error' => 'not_importable']);
        $this->postJson("/api/library/catalogue/{$linked->id}/import")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_answers_move_to_the_album_when_it_is_imported(): void
    {
        $this->spotifyHas([['a1', 'First Album', '1999']]);
        $row = $this->row('First Album', 1999);
        app(SpotifyAvailability::class)->lookupArtist($this->artist);

        $album = Album::create(['artist_id' => $this->artist->id, 'name' => 'First Album', 'source' => 'spotify']);

        $this->assertSame($album->id, $row->fresh()->album_id);
        $answer = AlbumAvailability::where('provider', 'spotify')->first();
        $this->assertSame($album->id, $answer->album_id);
        $this->assertNull($answer->catalogue_album_id);
    }
}
