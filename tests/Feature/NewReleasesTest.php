<?php

namespace Tests\Feature;

use App\Domain\Catalogue\NewReleaseScan;
use App\Domain\Library\Enrichment;
use App\Jobs\ScanNewReleases;
use App\Jobs\SyncArtistCatalogue;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Services\ListenBrainz\ListenBrainzClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** New releases of the library's artists: the ListenBrainz feed triggers the MusicBrainz sync, the catalogue holds the albums (docs/api/availability.md). */
class NewReleasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function artist(string $name, ?string $mbid): Artist
    {
        $artist = Artist::create(['name' => $name, 'source' => 'files']);
        if ($mbid) {
            Enrichment::save($artist, 'mbid', $mbid, 'string', Enrichment::MUSICBRAINZ);
        }

        return $artist;
    }

    private function release(string $group, array $artists, string $type = 'Album', string $name = 'New LP'): array
    {
        return ['release_group_mbid' => $group, 'artist_mbids' => $artists, 'release_name' => $name, 'release_group_primary_type' => $type, 'release_date' => now()->toDateString(), 'artist_credit_name' => 'X'];
    }

    public function test_the_client_reads_a_list_or_a_payload_and_skips_incomplete_rows(): void
    {
        Http::fake(['api.listenbrainz.org/*' => Http::sequence()
            ->push([$this->release('rg-1', ['a-1']), ['release_name' => 'no ids']])
            ->push(['payload' => ['releases' => [$this->release('rg-2', ['a-2'], 'EP')]]])]);

        $client = new ListenBrainzClient;
        $first = $client->freshReleases(30);
        $second = $client->freshReleases(500);

        $this->assertSame(['rg-1'], array_column($first, 'release_group_mbid'));
        $this->assertSame(['album', 'ep'], [$first[0]['type'], $second[0]['type']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'days=30') && $r->hasHeader('User-Agent'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'days=90'), 'days is capped at 90');
    }

    public function test_only_library_artists_with_an_unknown_release_group_are_synced(): void
    {
        Queue::fake();
        $new = $this->artist('Has News', 'a-new');
        $known = $this->artist('Known', 'a-known');
        $this->artist('No mbid', null);
        CatalogueAlbum::create(['artist_id' => $known->id, 'release_group_mbid' => 'rg-known', 'title' => 'Old', 'type' => 'album', 'seen_at' => now()]);
        Http::fake(['api.listenbrainz.org/*' => Http::response([
            $this->release('rg-new', ['a-new']),
            $this->release('rg-known', ['a-known']),
            $this->release('rg-stranger', ['a-stranger']),
            $this->release('rg-single', ['a-new'], 'Single'),
            $this->release('rg-collab', ['a-stranger', 'a-known'], 'EP'),
        ])]);

        $artists = (new NewReleaseScan)->run(30);

        $this->assertEqualsCanonicalizing(['Has News', 'Known'], array_map(fn ($a) => $a->name, $artists), 'the collab EP is new for Known');
        Queue::assertPushed(SyncArtistCatalogue::class, 2);
        Queue::assertPushedOn('catalogue', SyncArtistCatalogue::class);
    }

    public function test_dry_run_dispatches_nothing_and_the_command_queues_the_job(): void
    {
        Queue::fake();
        $this->artist('Has News', 'a-new');
        Http::fake(['api.listenbrainz.org/*' => Http::response([$this->release('rg-new', ['a-new'])])]);

        $this->artisan('catalogue:new-releases', ['--dry-run' => true])->expectsOutputToContain('would sync Has News')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('catalogue:new-releases', ['--days' => 14])->assertSuccessful();
        Queue::assertPushedOn('catalogue', ScanNewReleases::class, fn ($job) => $job->days === 14);
    }

    public function test_a_feed_outage_throws_so_the_job_is_retried(): void
    {
        Http::fake(['api.listenbrainz.org/*' => Http::response('busy', 503)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        (new ListenBrainzClient)->freshReleases();
    }

    public function test_the_endpoint_lists_recent_and_upcoming_albums_the_library_lacks(): void
    {
        $artist = $this->artist('Band', 'a-1');
        $row = fn (string $title, ?string $date, array $extra = []) => CatalogueAlbum::create($extra + ['artist_id' => $artist->id, 'release_group_mbid' => 'rg-'.md5($title), 'title' => $title, 'type' => 'album', 'first_release_date' => $date, 'first_release_year' => $date ? (int) substr($date, 0, 4) : null, 'seen_at' => now()]);
        $row('Last week', now()->subDays(7)->toDateString());
        $row('Next month', now()->addDays(30)->toDateString());
        $row('Ancient', '1999-05-01');
        $row('Year only', now()->format('Y'));
        $row('Hidden', now()->toDateString(), ['ignored_at' => now()]);
        $owned = \App\Models\Media\Album::create(['artist_id' => $artist->id, 'name' => 'Already mine', 'source' => 'files']);
        $row('Already mine', now()->subDay()->toDateString(), ['album_id' => $owned->id]);

        $data = $this->getJson('/api/library/new-releases')->assertOk()->json('data');

        $this->assertSame(['Next month', 'Last week'], array_column($data, 'title'));
        $this->assertSame(now()->addDays(30)->toDateString(), $data[0]['release_date']);
        $this->assertSame(['Next month', 'Last week'], array_column($this->getJson('/api/library/new-releases?days=10')->json('data'), 'title'));
        $this->assertSame(['Next month'], array_column($this->getJson('/api/library/new-releases?days=1')->json('data'), 'title'));
        $this->getJson('/api/library/new-releases?days=0')->assertStatus(422);

        Client::create(['name' => 'Touch', 'type' => 'multi', 'status' => 'approved', 'api_token' => 'tok', 'registration_token' => 'reg']);
        $this->getJson('/api/library/new-releases?client=tok')->assertForbidden();

        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'new_releases', 'days' => 10])->assertOk()->assertSee('Last week');
    }

    public function test_the_library_shows_the_shelf_only_when_there_is_something_new(): void
    {
        $this->get('/library')->assertOk()->assertDontSee('New from your artists');

        $artist = $this->artist('Band', 'a-1');
        CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => 'rg-1', 'title' => 'Fresh One', 'type' => 'album', 'first_release_date' => now()->subDays(3)->toDateString(), 'first_release_year' => (int) now()->format('Y'), 'seen_at' => now()]);

        $this->get('/library')->assertOk()->assertSee('New from your artists')->assertSee('Fresh One')->assertSee('Band');
    }
}
