<?php

namespace Tests\Feature;

use App\Domain\Library\Enrichment;
use App\Jobs\EnrichAlbumMusicBrainz;
use App\Jobs\EnrichArtistMusicBrainz;
use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Artist and album details from MusicBrainz, Wikipedia and the Cover Art Archive. */
class MusicBrainzDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): Artist
    {
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        Enrichment::save($artist, 'mbid', 'art-1', 'string', Enrichment::MUSICBRAINZ);

        return $artist;
    }

    private function album(Artist $artist, array $attributes = []): Album
    {
        $album = Album::create($attributes + ['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'dlna']);
        Enrichment::save($album, 'mbid', 'rel-1', 'string', Enrichment::MUSICBRAINZ);

        return $album;
    }

    private function fakeWikipedia(): array
    {
        return [
            'wikidata.org/*' => Http::response(['entities' => ['Q42' => ['sitelinks' => ['enwiki' => ['title' => 'The Band']]]]]),
            'en.wikipedia.org/*' => Http::response([
                'type' => 'standard', 'extract' => 'The Band is a band.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/The_Band']],
            ]),
        ];
    }

    public function test_artist_job_stores_details_links_genres_and_wikipedia_summary(): void
    {
        Sleep::fake();
        Http::fake($this->fakeWikipedia() + ['musicbrainz.org/*' => Http::response([
            'type' => 'Group', 'country' => 'NL', 'area' => ['name' => 'Amsterdam'],
            'life-span' => ['begin' => '1999-05', 'end' => null],
            'genres' => [['name' => 'indie rock', 'count' => 2], ['name' => 'Post Punk', 'count' => 9]],
            'tags' => [['name' => 'seen live', 'count' => 50]],
            'relations' => [
                ['type' => 'official homepage', 'url' => ['resource' => 'https://band.example']],
                ['type' => 'wikidata', 'url' => ['resource' => 'https://www.wikidata.org/wiki/Q42']],
                ['type' => 'social network', 'url' => ['resource' => 'https://www.instagram.com/band']],
                ['type' => 'lyrics', 'url' => ['resource' => 'https://lyrics.example/band']],
            ],
        ])]);
        $artist = $this->artist();

        EnrichArtistMusicBrainz::dispatchSync($artist);

        $details = $artist->fresh()->details();
        $this->assertSame('group', $details['type']);
        $this->assertSame('Amsterdam', $details['area']);
        $this->assertSame('1999-05', $details['begin_date']);
        $this->assertNull($details['end_date']);
        $this->assertSame('The Band is a band.', $details['bio']);
        $this->assertSame('https://en.wikipedia.org/wiki/The_Band', $details['wikipedia_url']);
        $this->assertEquals((object) [
            'official' => 'https://band.example', 'wikidata' => 'https://www.wikidata.org/wiki/Q42', 'instagram' => 'https://www.instagram.com/band',
        ], $details['links']);
        $this->assertSame(['Post Punk', 'Indie Rock'], $artist->genres(), 'curated genres by count, tags ignored');
        $this->assertTrue(Enrichment::isDone($artist, Enrichment::MUSICBRAINZ));
    }

    public function test_artist_job_without_wikipedia_keeps_the_lastfm_bio(): void
    {
        Sleep::fake();
        Http::fake(['musicbrainz.org/*' => Http::response(['type' => 'Person', 'tags' => [['name' => 'jazz', 'count' => 1]]])]);
        $artist = $this->artist();
        Enrichment::save($artist, 'bio', 'Last.fm bio', 'string', Enrichment::LASTFM);

        EnrichArtistMusicBrainz::dispatchSync($artist);

        $this->assertSame('Last.fm bio', $artist->fresh()->bio());
        $this->assertSame(['Jazz'], $artist->genres(), 'tags are used when there are no curated genres');
    }

    public function test_artist_outage_leaves_it_unmarked(): void
    {
        Sleep::fake();
        Http::fake(['musicbrainz.org/*' => Http::response('', 503)]);
        $artist = $this->artist();

        try {
            (new EnrichArtistMusicBrainz($artist))->handle(app(\App\Services\Wikipedia\WikipediaClient::class));
            $this->fail('Expected the outage to be thrown.');
        } catch (\Illuminate\Http\Client\RequestException) {
        }

        $this->assertFalse(Enrichment::isDone($artist, Enrichment::MUSICBRAINZ));
    }

    public function test_album_job_stores_release_details_genres_wikipedia_and_fills_the_date(): void
    {
        Sleep::fake();
        Queue::fake([ProcessArtwork::class]);
        Http::fake($this->fakeWikipedia() + [
            'coverartarchive.org/*' => Http::response('', 404),
            'musicbrainz.org/ws/2/release-group/*' => Http::response([
                'genres' => [['name' => 'shoegaze', 'count' => 4]],
                'relations' => [['type' => 'wikidata', 'url' => ['resource' => 'https://www.wikidata.org/wiki/Q42']]],
            ]),
            'musicbrainz.org/ws/2/release/*' => Http::response([
                'date' => '2011-03', 'country' => 'GB', 'barcode' => '123',
                'label-info' => [['catalog-number' => 'CAT-1', 'label' => ['name' => 'Label']]],
                'media' => [['track-count' => 5], ['track-count' => 7]],
                'release-group' => ['id' => 'rg-1', 'primary-type' => 'Album', 'secondary-types' => ['Live']],
                'genres' => [['name' => 'rock', 'count' => 1]],
            ]),
        ]);
        $album = $this->album($this->artist());

        EnrichAlbumMusicBrainz::dispatchSync($album);

        $album->refresh();
        $details = $album->details();
        $this->assertSame('Label', $details['label']);
        $this->assertSame('CAT-1', $details['catalog_number']);
        $this->assertSame('album', $details['release_type']);
        $this->assertSame(['live'], $details['secondary_types']);
        $this->assertSame(12, $details['track_count']);
        $this->assertSame(2, $details['disc_count']);
        $this->assertSame('GB', $details['country']);
        $this->assertSame('The Band is a band.', $details['summary']);
        $this->assertSame('2011-03-01', $album->released_at->toDateString());
        $this->assertSame(['Shoegaze', 'Rock'], $album->genres());
        $this->assertTrue(Enrichment::isDone($album, Enrichment::MUSICBRAINZ));
        Queue::assertNothingPushed();
    }

    public function test_album_without_an_image_gets_the_front_cover_from_the_archive(): void
    {
        Sleep::fake();
        Queue::fake([ProcessArtwork::class]);
        Http::fake([
            'musicbrainz.org/*' => Http::response([]),
            'coverartarchive.org/*' => Http::response(['images' => [
                ['front' => false, 'image' => 'http://coverartarchive.org/back.jpg'],
                ['front' => true, 'image' => 'http://coverartarchive.org/front.jpg', 'thumbnails' => ['1200' => 'http://coverartarchive.org/front-1200.jpg']],
            ]]),
        ]);
        $album = $this->album($this->artist());

        EnrichAlbumMusicBrainz::dispatchSync($album);

        $this->assertSame('https://coverartarchive.org/front-1200.jpg', $album->fresh()->images[0]['url']);
        Queue::assertPushed(ProcessArtwork::class);
    }

    public function test_album_with_an_image_keeps_it(): void
    {
        Sleep::fake();
        Http::fake(['musicbrainz.org/*' => Http::response([]), 'coverartarchive.org/*' => Http::response([], 500)]);
        $album = $this->album($this->artist(), ['images' => [['url' => 'https://own.example/cover.jpg']]]);

        EnrichAlbumMusicBrainz::dispatchSync($album);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'coverartarchive'));
        $this->assertSame('https://own.example/cover.jpg', $album->fresh()->images[0]['url']);
    }

    public function test_entities_with_an_mbid_but_no_details_are_queued(): void
    {
        Queue::fake();
        $artist = $this->artist();
        $album = $this->album($artist);
        $done = $this->album($artist, ['name' => 'Done']);
        Enrichment::markDone($done, Enrichment::MUSICBRAINZ);
        Album::create(['artist_id' => $artist->id, 'name' => 'No mbid', 'source' => 'dlna']);

        $this->assertSame(2, Enrichment::queueEntities(10));
        Queue::assertPushed(EnrichArtistMusicBrainz::class, fn ($job) => $job->artist->is($artist));
        Queue::assertPushed(EnrichAlbumMusicBrainz::class, 1);
        Queue::assertPushed(EnrichAlbumMusicBrainz::class, fn ($job) => $job->album->is($album));
    }

    public function test_artist_and_album_pages_show_the_details(): void
    {
        $artist = $this->artist();
        $album = $this->album($artist, ['released_at' => '2011-03-01']);
        foreach (['wikipedia_extract' => 'The Band is a band.', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/The_Band', 'artist_type' => 'group', 'begin_date' => '1999', 'links' => '{"official":"https://band.example"}'] as $key => $value) {
            Enrichment::save($artist, $key, $value, 'string', 'test');
        }
        foreach (['label' => 'Fancy Label', 'release_type' => 'album', 'track_count' => '12', 'wikipedia_extract' => 'An album about things.'] as $key => $value) {
            Enrichment::save($album, $key, $value, 'string', 'test');
        }

        $this->get("/artists/{$artist->id}")->assertOk()
            ->assertSee('The Band is a band.')->assertSee('Since 1999')->assertSee('https://band.example', false)->assertSee('Wikipedia');
        $this->get("/albums/{$album->id}")->assertOk()
            ->assertSee('Album · 2011 · Fancy Label · 12 tracks')->assertSee('An album about things.');
    }
}
