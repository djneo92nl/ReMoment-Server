<?php

namespace Tests\Feature;

use App\Domain\Catalogue\ArtistCatalogueSync;
use App\Domain\Catalogue\CatalogueLinker;
use App\Domain\Library\Enrichment;
use App\Jobs\SyncArtistCatalogue;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\CatalogueArtist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The MusicBrainz discography sync and the link between release-groups and library albums (docs/architecture/availability-layer.md). */
class CatalogueSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private function artist(?string $mbid = 'art-1'): Artist
    {
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        if ($mbid) {
            Enrichment::save($artist, 'mbid', $mbid, 'string', Enrichment::MUSICBRAINZ);
        }

        return $artist;
    }

    private function group(string $id, string $title, string $type = 'Album', string $date = '1999-05-01', array $secondary = []): array
    {
        return ['id' => $id, 'title' => $title, 'primary-type' => $type, 'secondary-types' => $secondary, 'first-release-date' => $date, 'disambiguation' => ''];
    }

    private function fakeBrowse(array ...$answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers as $groups) {
            $sequence->push(['release-groups' => $groups, 'release-group-count' => count($groups)]);
        }
        Http::fake(['musicbrainz.org/ws/2/release-group*' => $sequence]);
    }

    public function test_sync_stores_albums_and_eps_only(): void
    {
        $this->fakeBrowse([
            $this->group('rg-1', 'First'),
            $this->group('rg-2', 'Short', 'EP', '2001'),
            $this->group('rg-3', 'Hit Single', 'Single'),
            $this->group('rg-4', 'Live at Home', 'Album', '2003', ['Live']),
            $this->group('rg-5', 'Best Of', 'Album', '2005', ['Compilation']),
        ]);
        $artist = $this->artist();

        $this->assertSame('ok', (new ArtistCatalogueSync)->sync($artist));

        $rows = CatalogueAlbum::where('artist_id', $artist->id)->orderBy('release_group_mbid')->get();
        $this->assertSame(['rg-1', 'rg-2'], $rows->pluck('release_group_mbid')->all());
        $this->assertSame(['album', 'ep'], $rows->pluck('type')->all());
        $this->assertSame([1999, 2001], $rows->pluck('first_release_year')->all());
        $this->assertSame('1999-05-01', $rows[0]->first_release_date);
        $this->assertSame('ok', CatalogueArtist::find($artist->id)->status);
        $this->assertNotNull(CatalogueArtist::find($artist->id)->next_sync_at);
    }

    public function test_sync_pages_through_more_than_a_hundred_groups(): void
    {
        $page = fn (int $from, int $to) => array_map(fn ($i) => $this->group("rg-{$i}", "Album {$i}"), range($from, $to));
        $this->fakeBrowse($page(1, 100), $page(101, 105));
        $artist = $this->artist();

        (new ArtistCatalogueSync)->sync($artist);

        $this->assertSame(105, CatalogueAlbum::where('artist_id', $artist->id)->count());
        Http::assertSentCount(2);
    }

    public function test_sync_is_idempotent_and_keeps_wishes_of_vanished_groups(): void
    {
        $artist = $this->artist();
        $renamed = [$this->group('rg-1', 'First (renamed)')];
        $this->fakeBrowse([$this->group('rg-1', 'First'), $this->group('rg-2', 'Second')], $renamed, $renamed, $renamed);
        (new ArtistCatalogueSync)->sync($artist);
        CatalogueAlbum::where('release_group_mbid', 'rg-2')->update(['wanted_at' => now()]);

        (new ArtistCatalogueSync)->sync($artist);
        (new ArtistCatalogueSync)->sync($artist);

        $this->assertSame(2, CatalogueAlbum::where('artist_id', $artist->id)->count(), 'the wished group stays');
        $this->assertSame('First (renamed)', CatalogueAlbum::where('release_group_mbid', 'rg-1')->value('title'));

        CatalogueAlbum::where('release_group_mbid', 'rg-2')->update(['wanted_at' => null]);
        (new ArtistCatalogueSync)->sync($artist);
        $this->assertSame(['rg-1'], CatalogueAlbum::where('artist_id', $artist->id)->pluck('release_group_mbid')->all());
    }

    public function test_artist_unknown_to_musicbrainz_is_recorded_as_no_mbid(): void
    {
        $artist = $this->artist(null);
        Http::fake(['musicbrainz.org/ws/2/artist*' => Http::response(['artists' => []])]);

        $this->assertSame('no_mbid', (new ArtistCatalogueSync)->sync($artist));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'release-group'));
        $this->assertSame('no_mbid', CatalogueArtist::find($artist->id)->status);
    }

    public function test_unknown_artist_is_not_found_and_an_outage_throws_without_writing(): void
    {
        $artist = $this->artist();
        Http::fake(['musicbrainz.org/*' => Http::sequence()->push(['error' => 'Not Found'], 404)->push('busy', 503)]);
        $this->assertSame('not_found', (new ArtistCatalogueSync)->sync($artist));

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        (new ArtistCatalogueSync)->sync($artist);
    }

    public function test_owned_album_links_by_name_and_closes_the_wish(): void
    {
        $artist = $this->artist();
        $this->fakeBrowse([$this->group('rg-1', 'First Album')]);
        (new ArtistCatalogueSync)->sync($artist);
        $row = CatalogueAlbum::first();
        $row->update(['wanted_at' => now()]);

        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album (Remastered 2011)', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'One', 'source' => 'files', 'external_id' => 'local:file:1']);
        CatalogueLinker::link($album);

        $row->refresh();
        $this->assertSame($album->id, $row->album_id);
        $this->assertSame('name', $row->link_method);
        $this->assertNotNull($row->fulfilled_at, 'own files close the wish');
    }

    public function test_streaming_only_album_links_but_does_not_close_the_wish(): void
    {
        $artist = $this->artist();
        $this->fakeBrowse([$this->group('rg-1', 'First Album')]);
        (new ArtistCatalogueSync)->sync($artist);

        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'spotify']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'One', 'source' => 'spotify', 'external_id' => 'spotify:track:1']);
        CatalogueLinker::link($album);

        $row = CatalogueAlbum::first();
        $this->assertSame($album->id, $row->album_id);
        $this->assertNull($row->fulfilled_at);
    }

    public function test_a_new_album_is_linked_by_the_model_event_and_by_release_group_id(): void
    {
        $artist = $this->artist();
        $this->fakeBrowse([$this->group('rg-1', 'Original Title')]);
        (new ArtistCatalogueSync)->sync($artist);

        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Something Else Entirely', 'source' => 'files']);
        $this->assertNull(CatalogueAlbum::first()->album_id);

        Enrichment::save($album, 'release_group_mbid', 'rg-1', 'string', Enrichment::MUSICBRAINZ);
        CatalogueLinker::link($album);
        $this->assertSame('mbid', CatalogueAlbum::first()->link_method);

        $other = Album::create(['artist_id' => $artist->id, 'name' => 'Original Title', 'source' => 'files']);
        $this->assertNotSame($other->id, CatalogueAlbum::first()->album_id, 'a linked row is not taken by a second album');
    }

    public function test_self_titled_albums_link_only_when_one_year_is_clearly_closest(): void
    {
        $artist = $this->artist();
        $this->fakeBrowse([
            $this->group('rg-blue', 'Weezer', 'Album', '1994'),
            $this->group('rg-green', 'Weezer', 'Album', '2001'),
        ]);
        (new ArtistCatalogueSync)->sync($artist);

        $unknown = Album::create(['artist_id' => $artist->id, 'name' => 'Weezer', 'source' => 'files']);
        $this->assertNull(CatalogueAlbum::whereNotNull('album_id')->first(), 'no year: no guess');
        $unknown->delete();

        Album::create(['artist_id' => $artist->id, 'name' => 'Weezer', 'source' => 'files', 'released_at' => '2001-05-15']);
        $this->assertSame('rg-green', CatalogueAlbum::whereNotNull('album_id')->value('release_group_mbid'));
    }

    public function test_wish_and_ignore_exclude_each_other(): void
    {
        $artist = $this->artist();
        $row = CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => 'rg-1', 'title' => 'X', 'type' => 'album', 'seen_at' => now()]);

        $row->update(['wanted_at' => now()]);
        $row->update(['ignored_at' => now()]);
        $this->assertNull($row->fresh()->wanted_at);

        $row->update(['wanted_at' => now()]);
        $this->assertNull($row->fresh()->ignored_at);
    }

    public function test_removing_the_album_keeps_the_row_as_missing(): void
    {
        $artist = $this->artist();
        $this->fakeBrowse([$this->group('rg-1', 'First')]);
        (new ArtistCatalogueSync)->sync($artist);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First', 'source' => 'files']);
        $this->assertSame($album->id, CatalogueAlbum::first()->album_id);

        $album->delete();

        $this->assertNull(CatalogueAlbum::first()->album_id);
    }

    public function test_sync_command_queues_due_artists_own_files_first_and_job_runs_on_the_catalogue_queue(): void
    {
        Queue::fake();
        $streaming = Artist::create(['name' => 'Aaa', 'source' => 'spotify']);
        Album::create(['artist_id' => $streaming->id, 'name' => 'S', 'source' => 'spotify']);
        $files = Artist::create(['name' => 'Zzz', 'source' => 'files']);
        $album = Album::create(['artist_id' => $files->id, 'name' => 'F', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $files->id, 'name' => 'T', 'source' => 'files', 'external_id' => 'local:file:9']);
        CatalogueArtist::create(['artist_id' => $streaming->id, 'status' => 'ok', 'next_sync_at' => now()->addDays(10)]);

        $this->artisan('catalogue:sync', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushedOn('catalogue', SyncArtistCatalogue::class, fn ($job) => $job->artist->id === $files->id);
        Queue::assertPushed(SyncArtistCatalogue::class, 1);
    }

    private function fakeSearchThenBrowse(array $artists, array $groups): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/artist*' => Http::response(['artists' => $artists]),
            'musicbrainz.org/ws/2/release-group*' => Http::response(['release-groups' => $groups]),
        ]);
    }

    public function test_artist_without_mbid_is_discovered_and_verified_by_an_owned_album(): void
    {
        $artist = $this->artist(null);
        Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        $this->fakeSearchThenBrowse([['id' => 'art-9', 'name' => 'Band', 'score' => 100], ['id' => 'art-8', 'name' => 'Bandit', 'score' => 70]], [$this->group('rg-1', 'First Album')]);

        $this->assertSame('ok', (new ArtistCatalogueSync)->sync($artist));

        $this->assertSame('art-9', Enrichment::mbid($artist));
        $this->assertSame($artist->albums()->value('id'), CatalogueAlbum::first()->album_id, 'linked in the same run');
    }

    public function test_a_namesake_without_a_shared_album_is_not_taken(): void
    {
        $artist = $this->artist(null);
        Album::create(['artist_id' => $artist->id, 'name' => 'My Own Record', 'source' => 'files']);
        $this->fakeSearchThenBrowse([['id' => 'art-9', 'name' => 'Band', 'score' => 100]], [$this->group('rg-1', 'Totally Different')]);

        $this->assertSame('unverified', (new ArtistCatalogueSync)->sync($artist));

        $this->assertNull(Enrichment::mbid($artist));
        $this->assertSame(0, CatalogueAlbum::count());
    }

    public function test_two_equally_named_candidates_are_not_taken(): void
    {
        $artist = $this->artist(null);
        Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        $this->fakeSearchThenBrowse([['id' => 'a', 'name' => 'Band', 'score' => 100], ['id' => 'b', 'name' => 'Band', 'score' => 98]], []);

        $this->assertSame('unverified', (new ArtistCatalogueSync)->sync($artist));
        $this->assertNull(Enrichment::mbid($artist));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'release-group'));
    }

    public function test_a_weak_score_is_not_taken(): void
    {
        $artist = $this->artist(null);
        $this->fakeSearchThenBrowse([['id' => 'a', 'name' => 'Band', 'score' => 80]], []);

        $this->assertSame('no_mbid', (new ArtistCatalogueSync)->sync($artist));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'release-group'));
    }
}
