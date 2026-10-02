<?php

namespace Tests\Feature;

use App\Domain\Library\Enrichment;
use App\Jobs\EnrichAlbumAudioDb;
use App\Jobs\EnrichAlbumDiscogs;
use App\Jobs\EnrichAlbumLastfm;
use App\Jobs\EnrichArtistAudioDb;
use App\Jobs\EnrichArtistLastfm;
use App\Jobs\EnrichTrackLastfm;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Last.fm track/album/artist info, TheAudioDB and Discogs. */
class ExtraSourcesEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['lastfm.api_key' => 'key']);
    }

    private function records(): array
    {
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Song', 'source' => 'dlna']);

        return [$artist, $album, $track];
    }

    public function test_lastfm_artist_stores_bio_stats_and_tags_and_marks_done(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['artist' => [
            'bio' => ['summary' => 'A band. <a href="https://last.fm/x">Read more on Last.fm</a>'],
            'similar' => ['artist' => [['name' => 'Other']]],
            'stats' => ['listeners' => '1200', 'playcount' => '99000'],
            'tags' => ['tag' => [['name' => 'shoegaze'], ['name' => 'seen live'], ['name' => 'dream pop']]],
        ]])]);
        [$artist] = $this->records();

        EnrichArtistLastfm::dispatchSync($artist);

        $details = $artist->fresh()->details();
        $this->assertSame('A band.', $details['bio']);
        $this->assertSame(1200, $details['listeners']);
        $this->assertSame(99000, $details['playcount']);
        $this->assertSame(['Shoegaze', 'Dream Pop'], $details['tags'], 'noise tags dropped');
        $this->assertSame(['Shoegaze', 'Dream Pop'], $artist->genres(), 'tags stand in while there are no genres');
        $this->assertTrue(Enrichment::isDone($artist, Enrichment::LASTFM));
    }

    public function test_lastfm_tags_do_not_add_genres_once_there_are_some(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['artist' => ['tags' => ['tag' => [['name' => 'chillout']]]]])]);
        [$artist] = $this->records();
        Enrichment::save($artist, 'genres', json_encode(['jazz']), 'json', 'musicbrainz');

        EnrichArtistLastfm::dispatchSync($artist);

        $this->assertSame(['Jazz'], $artist->genres());
        $this->assertSame(['Chillout'], $artist->fresh()->details()['tags']);
    }

    public function test_lastfm_track_and_album_info(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::sequence()
            ->push(['track' => ['listeners' => '10', 'playcount' => '20', 'toptags' => ['tag' => ['name' => 'rock']]]])
            ->push(['album' => ['listeners' => '5', 'playcount' => '6', 'wiki' => ['summary' => 'About the LP.'], 'tags' => ['tag' => [['name' => 'indie']]]]]),
        ]);
        [, $album, $track] = $this->records();

        EnrichTrackLastfm::dispatchSync($track);
        EnrichAlbumLastfm::dispatchSync($album);

        $this->assertSame(10, $track->fresh()->details()['listeners']);
        $this->assertSame(['Rock'], $track->genres(), 'a lone tag is returned as an object by Last.fm');
        $this->assertSame('About the LP.', $album->fresh()->details()['summary']);
        $this->assertSame(5, $album->fresh()->details()['listeners']);
        $this->assertTrue(Enrichment::isDone($track, Enrichment::LASTFM));
        $this->assertTrue(Enrichment::isDone($album, Enrichment::LASTFM));
    }

    public function test_lastfm_not_found_marks_done_but_a_rate_limit_is_retried(): void
    {
        Http::fake(['ws.audioscrobbler.com/*' => Http::sequence()
            ->push(['error' => 6, 'message' => 'Artist not found'])
            ->push(['error' => 29, 'message' => 'Rate limit exceeded']),
        ]);
        [$artist, , $track] = $this->records();

        EnrichArtistLastfm::dispatchSync($artist);
        $this->assertTrue(Enrichment::isDone($artist, Enrichment::LASTFM));

        try {
            EnrichTrackLastfm::dispatchSync($track);
            $this->fail('Expected the rate limit to be thrown.');
        } catch (\RuntimeException) {
        }
        $this->assertFalse(Enrichment::isDone($track, Enrichment::LASTFM));
    }

    public function test_audiodb_stores_artist_images_mood_and_bio(): void
    {
        Http::fake(['theaudiodb.com/*' => Http::response(['artists' => [[
            'idArtist' => '111', 'strArtistThumb' => 'https://img.test/thumb.jpg', 'strArtistFanart' => 'https://img.test/fan.jpg',
            'strArtistLogo' => null, 'strMood' => 'Dreamy', 'strStyle' => 'Rock/Pop', 'strGenre' => 'Shoegaze',
            'strBiographyEN' => 'AudioDB bio.',
        ]]])]);
        [$artist] = $this->records();
        Enrichment::save($artist, 'mbid', 'art-1', 'string', Enrichment::MUSICBRAINZ);

        EnrichArtistAudioDb::dispatchSync($artist);

        $details = $artist->fresh()->details();
        $this->assertEquals((object) ['thumb' => 'https://img.test/thumb.jpg', 'fanart' => 'https://img.test/fan.jpg'], $details['images']);
        $this->assertSame('Dreamy', $details['mood']);
        $this->assertSame('AudioDB bio.', $details['bio'], 'last in line after Wikipedia and Last.fm');
        $this->assertSame(['Shoegaze', 'Rock/Pop'], $artist->genres());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'artist-mb.php?i=art-1'));
    }

    public function test_audiodb_album_and_missing_data(): void
    {
        Http::fake(['theaudiodb.com/*' => Http::sequence()
            ->push(['album' => [['idAlbum' => '5', 'strMood' => 'Sad', 'strTheme' => 'Rainy day', 'intScore' => '8.66', 'strDescriptionEN' => 'Described.']]])
            ->push(['album' => null]),
        ]);
        [$artist, $album] = $this->records();
        $other = Album::create(['artist_id' => $artist->id, 'name' => 'Other', 'source' => 'dlna']);
        Enrichment::save($album, 'release_group_mbid', 'rg-1', 'string', Enrichment::MUSICBRAINZ);
        Enrichment::save($other, 'release_group_mbid', 'rg-2', 'string', Enrichment::MUSICBRAINZ);

        EnrichAlbumAudioDb::dispatchSync($album);
        EnrichAlbumAudioDb::dispatchSync($other);

        $details = $album->fresh()->details();
        $this->assertSame('Sad', $details['mood']);
        $this->assertSame('Rainy day', $details['theme']);
        $this->assertSame(8.7, $details['rating']);
        $this->assertSame('Described.', $details['summary']);
        $this->assertTrue(Enrichment::isDone($other, Enrichment::AUDIODB), 'nothing there is still done');
    }

    public function test_discogs_needs_a_token_and_an_exact_title_match(): void
    {
        [, $album] = $this->records();

        EnrichAlbumDiscogs::dispatchSync($album);
        $this->assertFalse(Enrichment::isDone($album, Enrichment::DISCOGS), 'without a token nothing is asked or marked');

        config(['metadata.discogs_token' => 'tok']);
        Http::fake([
            'api.discogs.com/database/search*' => Http::response(['results' => [
                ['id' => 1, 'title' => 'Band - LP (Deluxe Tour Edition Box)'],
                ['id' => 2, 'title' => 'Band (3) - LP'],
            ]]),
            'api.discogs.com/releases/2' => Http::response([
                'id' => 2, 'uri' => 'https://www.discogs.com/release/2',
                'formats' => [['name' => 'Vinyl', 'descriptions' => ['LP', 'Album']]],
                'styles' => ['Shoegaze'], 'genres' => ['Rock'],
                'extraartists' => [
                    ['name' => 'Pro Ducer (2)', 'role' => 'Producer'],
                    ['name' => 'Mas Terer', 'role' => 'Mastered By [Assisted]'],
                    ['name' => 'Pro Ducer (2)', 'role' => 'Producer'],
                ],
            ]),
        ]);

        EnrichAlbumDiscogs::dispatchSync($album);

        $details = $album->fresh()->details();
        $this->assertSame('Vinyl, LP, Album', $details['format']);
        $this->assertSame('https://www.discogs.com/release/2', $details['discogs_url']);
        $this->assertSame(['Shoegaze', 'Rock'], $album->genres());
        $this->assertEquals((object) ['producer' => ['Pro Ducer'], 'mastered by' => ['Mas Terer']], $details['credits']);
        $this->assertTrue(Enrichment::isDone($album, Enrichment::DISCOGS));
    }

    public function test_queue_adds_the_album_and_artist_jobs_for_enabled_sources(): void
    {
        Queue::fake();
        config(['metadata.discogs_token' => 'tok']);
        [, , $track] = $this->records();

        Enrichment::queue($track->load('artist', 'album'));

        Queue::assertPushed(EnrichTrackLastfm::class);
        Queue::assertPushed(EnrichArtistLastfm::class);
        Queue::assertPushed(EnrichAlbumLastfm::class);
        Queue::assertPushed(EnrichAlbumDiscogs::class);
        Queue::assertNotPushed(EnrichArtistAudioDb::class, 'needs the MusicBrainz id first');
        Queue::assertNotPushed(EnrichAlbumAudioDb::class);
    }

    public function test_unknown_albums_are_not_looked_up(): void
    {
        Queue::fake();
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Unknown Album', 'source' => 'dlna']);

        $this->assertSame(0, Enrichment::queueAlbum($album));
        $this->assertSame(0, Enrichment::albumBacklog()->count());
    }

    public function test_pages_show_the_new_data(): void
    {
        [$artist, $album, $track] = $this->records();
        Enrichment::save($artist, 'images', json_encode(['thumb' => 'https://img.test/thumb.jpg']), 'json', Enrichment::AUDIODB);
        Enrichment::save($artist, 'mood', 'Dreamy', 'string', Enrichment::AUDIODB);
        Enrichment::save($artist, 'lastfm_listeners', '1200', 'int', Enrichment::LASTFM);
        Enrichment::save($artist, 'lastfm_playcount', '99000', 'int', Enrichment::LASTFM);
        Enrichment::save($album, 'format', 'Vinyl, LP', 'string', Enrichment::DISCOGS);
        Enrichment::save($album, 'rating', '8.7', 'float', Enrichment::AUDIODB);
        Enrichment::save($album, 'credits', json_encode([['role' => 'producer', 'name' => 'Pro Ducer']]), 'json', Enrichment::DISCOGS);
        Enrichment::save($track, 'lastfm_listeners', '10', 'int', Enrichment::LASTFM);
        Enrichment::save($track, 'lastfm_playcount', '20', 'int', Enrichment::LASTFM);

        $this->get("/artists/{$artist->id}")->assertOk()
            ->assertSee('https://img.test/thumb.jpg', false)->assertSee('Dreamy')->assertSee('1,200 listeners · 99,000 scrobbles');
        $this->get("/albums/{$album->id}")->assertOk()
            ->assertSee('Vinyl, LP')->assertSee('★ 8.7 / 10')->assertSeeInOrder(['Credits', 'Producer', 'Pro Ducer'])
            ->assertSee('10 listeners · 20 plays');
    }
}
