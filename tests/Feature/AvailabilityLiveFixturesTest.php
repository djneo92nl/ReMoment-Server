<?php

namespace Tests\Feature;

use App\Domain\Catalogue\ArtistCatalogueSync;
use App\Domain\Catalogue\NewReleaseScan;
use App\Domain\Catalogue\PhysicalItems;
use App\Domain\Library\Enrichment;
use App\Jobs\SyncArtistCatalogue;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\CatalogueArtist;
use App\Services\ListenBrainz\ListenBrainzClient;
use App\Services\MusicBrainz\MusicBrainzClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The MusicBrainz and ListenBrainz calls against trimmed samples of their real answers (tests/Fixtures/musicbrainz and
 * /listenbrainz, recorded 2026-10-30; docs/architecture/availability-layer.md, "Verified against live services").
 */
class AvailabilityLiveFixturesTest extends TestCase
{
    use RefreshDatabase;

    private const DIRTY_THREE = 'af8bb921-4d67-4f0d-ba17-6215315f92e4';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/{$name}")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_release_group_browse_keeps_albums_and_eps_without_secondary_types(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release-group*' => Http::response($this->fixture('musicbrainz/release-group-browse-dirty-three.json'))]);

        $groups = (new MusicBrainzClient)->releaseGroups(self::DIRTY_THREE);

        $this->assertSame(['Dirty Three', 'Sharks EP', 'A Strange Holiday'], array_column($groups, 'title'), 'live and demo groups are dropped');
        $this->assertSame(['album', 'ep', 'ep'], array_column($groups, 'type'));
        $this->assertSame(['1994', '1998', '2003'], array_column($groups, 'date'));
        Http::assertSent(function ($r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['artist'] === self::DIRTY_THREE && $q['type'] === 'album|ep' && $q['release-group-status'] === 'website-default'
                && $q['limit'] === '100' && $q['offset'] === '0' && $q['fmt'] === 'json' && str_contains($r->header('User-Agent')[0], '@');
        });
    }

    public function test_an_unknown_date_is_null_and_a_future_date_is_kept(): void
    {
        $sample = $this->fixture('musicbrainz/release-group-browse-dirty-three.json');
        $sample['release-groups'] = array_values(array_filter($sample['release-groups'], fn ($g) => in_array($g['title'], ['Laundromat, Cincinatti, OH', 'Ulterior Motives'])));
        foreach ($sample['release-groups'] as &$g) {
            $g['secondary-types'] = []; // as if they were plain albums: only the dates are under test
        }
        Http::fake(['musicbrainz.org/ws/2/release-group*' => Http::response($sample)]);

        $groups = (new MusicBrainzClient)->releaseGroups(self::DIRTY_THREE);

        $this->assertSame([null, '2026-11-06'], array_column($groups, 'date'));
    }

    public function test_an_unknown_artist_is_a_404_with_an_error_body_and_not_found_status(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release-group*' => Http::response($this->fixture('musicbrainz/release-group-browse-404.json'), 404)]);
        $artist = Artist::create(['name' => 'Gone', 'source' => 'files']);
        Enrichment::save($artist, 'mbid', '00000000-0000-4000-8000-000000000000', 'string', Enrichment::MUSICBRAINZ);

        $this->assertNull((new MusicBrainzClient)->releaseGroups('00000000-0000-4000-8000-000000000000'));
        $this->assertSame('not_found', (new ArtistCatalogueSync)->sync($artist));
        $this->assertSame('not_found', CatalogueArtist::find($artist->id)->status);
    }

    public function test_the_busy_503_throws_so_the_job_is_retried_later(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release-group*' => Http::response(['error' => 'The MusicBrainz web server is currently busy. Please try again later.'], 503)]);
        $artist = Artist::create(['name' => 'Busy', 'source' => 'files']);
        Enrichment::save($artist, 'mbid', self::DIRTY_THREE, 'string', Enrichment::MUSICBRAINZ);

        try {
            (new ArtistCatalogueSync)->sync($artist);
            $this->fail('a 503 must throw');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $this->assertSame(503, $e->response->status());
        }
        $this->assertSame(0, CatalogueAlbum::count());
        $this->assertNull(CatalogueArtist::find($artist->id), 'nothing is recorded for a failed run');
        $this->assertSame([60, 300, 900, 1800, 3600], (new SyncArtistCatalogue($artist))->backoff());
    }

    public function test_the_artist_search_gives_id_name_and_score(): void
    {
        Http::fake(['musicbrainz.org/ws/2/artist*' => Http::response($this->fixture('musicbrainz/artist-search-radiohead.json'))]);

        $found = (new MusicBrainzClient)->searchArtists('Radiohead');

        $this->assertSame(['a74b1b7f-71a5-4011-9441-d0b5e4122711', 'Radiohead', 100], array_values($found[0]));
        $this->assertCount(2, $found);
        Http::assertSent(fn ($r) => urldecode($r->url()) !== $r->url() && str_contains(urldecode($r->url()), 'query=artist:"Radiohead"'));
    }

    public function test_the_barcode_lookup_reads_a_real_release(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response($this->fixture('musicbrainz/release-search-barcode-nevermind.json'))]);

        $found = PhysicalItems::lookupBarcode('0720642442517');

        $this->assertSame([[
            'release_mbid' => 'f922ec87-4758-421d-a839-3193455345ff',
            'release_group_mbid' => '1b022e01-4da6-387b-8658-8678046e4cef',
            'artist' => 'Nirvana',
            'title' => 'Nevermind',
            'year' => 1991,
            'label' => 'DGC Records',
            'catalogue_number' => 'DGC-24425',
            'country' => 'US',
            'medium' => 'vinyl',
        ]], $found, 'the format of this barcode really is 12" Vinyl');
    }

    public function test_the_fresh_releases_feed_is_a_payload_with_releases(): void
    {
        Http::fake(['api.listenbrainz.org/*' => Http::response($this->fixture('listenbrainz/fresh-releases.json'))]);

        $releases = (new ListenBrainzClient)->freshReleases(14);

        $this->assertSame(['album', 'ep', 'album', 'single', null], array_column($releases, 'type'));
        $this->assertSame([null, null, 'live', null, null], array_column($releases, 'secondary_type'));
        $this->assertSame('Autosmile', $releases[0]['title']);
        $this->assertSame(['893b6621-4877-4671-8a7c-b9971f723837'], $releases[0]['artist_mbids']);
        $this->assertSame('2026-10-16', $releases[0]['date']);
        Http::assertSent(fn ($r) => !$r->hasHeader('Authorization') && str_contains($r->url(), 'days=14'));
    }

    public function test_the_scan_ignores_live_and_compilation_releases_of_the_feed(): void
    {
        Queue::fake();
        $feed = $this->fixture('listenbrainz/fresh-releases.json');
        $live = $feed['payload']['releases'][2];
        $album = $feed['payload']['releases'][0];
        $a = Artist::create(['name' => 'Live Band', 'source' => 'files']);
        Enrichment::save($a, 'mbid', $live['artist_mbids'][0], 'string', Enrichment::MUSICBRAINZ);
        $b = Artist::create(['name' => 'Studio Band', 'source' => 'files']);
        Enrichment::save($b, 'mbid', $album['artist_mbids'][0], 'string', Enrichment::MUSICBRAINZ);
        Http::fake(['api.listenbrainz.org/*' => Http::response($feed)]);

        $artists = (new NewReleaseScan)->run(30);

        $this->assertSame(['Studio Band'], array_map(fn ($x) => $x->name, $artists));
    }
}
