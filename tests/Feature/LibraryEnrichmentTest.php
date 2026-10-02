<?php

namespace Tests\Feature;

use App\Domain\Library\Enrichment;
use App\Jobs\EnrichArtistLastfm;
use App\Jobs\EnrichTrackLyrics;
use App\Jobs\EnrichTrackMusicBrainz;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Per-source enrichment: markers, retries, the backlog command and its limit. */
class LibraryEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $name = 'Song', string $artist = 'Band', string $source = 'dlna'): Track
    {
        $artist = Artist::firstOrCreate(['name' => $artist], ['source' => $source]);

        return Track::create(['artist_id' => $artist->id, 'name' => $name, 'source' => $source, 'external_id' => $name]);
    }

    public function test_queue_dispatches_each_missing_source_once(): void
    {
        Queue::fake();
        config(['lastfm.api_key' => 'key']);
        $track = $this->track();

        $this->assertSame(3, Enrichment::queue($track));
        Queue::assertPushed(EnrichTrackMusicBrainz::class);
        Queue::assertPushed(EnrichTrackLyrics::class);
        Queue::assertPushed(EnrichArtistLastfm::class);

        Enrichment::markDone($track, Enrichment::MUSICBRAINZ);
        Enrichment::markDone($track, Enrichment::LRCLIB);
        Enrichment::markDone($track->artist, Enrichment::LASTFM);

        $this->assertSame(0, Enrichment::queue($track->fresh()));
    }

    public function test_unknown_artist_is_never_enriched(): void
    {
        Queue::fake();

        $this->assertSame(0, Enrichment::queue($this->track('Song', 'Unknown Artist')));
        Queue::assertNothingPushed();
        $this->assertSame(0, Enrichment::backlog()->count());
    }

    public function test_legacy_marker_counts_as_done(): void
    {
        Queue::fake();
        $track = $this->track();
        Enrichment::markDone($track, 'system');

        $this->assertSame(0, Enrichment::queue($track));
        $this->assertSame(0, Enrichment::backlog()->count());
    }

    public function test_musicbrainz_job_stores_data_and_marks_done(): void
    {
        Sleep::fake();
        Http::fake([
            'musicbrainz.org/ws/2/recording?*' => Http::response(['recordings' => [[
                'id' => 'rec-1', 'score' => 100,
                'artist-credit' => [['artist' => ['id' => 'art-1']]],
                'releases' => [['id' => 'rel-1']],
            ]]]),
            'musicbrainz.org/ws/2/recording/*' => Http::response(['isrcs' => ['NLA000000001']]),
            'musicbrainz.org/ws/2/artist/*' => Http::response(['tags' => [['name' => 'rock', 'count' => 3]], 'country' => 'NL']),
            'musicbrainz.org/ws/2/release/*' => Http::response(['label-info' => [['label' => ['name' => 'Label']]]]),
        ]);
        $track = $this->track();
        $track->album()->associate(\App\Models\Media\Album::create(['artist_id' => $track->artist_id, 'name' => 'LP', 'source' => 'dlna']))->save();

        (new EnrichTrackMusicBrainz($track))->handle();

        $this->assertSame('NLA000000001', $track->metadata()->where('key', 'isrc')->value('value'));
        $this->assertSame('["rock"]', $track->artist->metadata()->where('key', 'genres')->value('value'));
        $this->assertSame('Label', $track->album->metadata()->where('key', 'label')->value('value'));
        $this->assertTrue(Enrichment::isDone($track, Enrichment::MUSICBRAINZ));
    }

    public function test_musicbrainz_outage_throws_and_leaves_the_track_unmarked(): void
    {
        Sleep::fake();
        Http::fake(['musicbrainz.org/*' => Http::response('', 503)]);
        $track = $this->track();

        try {
            (new EnrichTrackMusicBrainz($track))->handle();
            $this->fail('Expected the outage to be thrown so the job is retried.');
        } catch (RequestException) {
        }

        $this->assertFalse(Enrichment::isDone($track, Enrichment::MUSICBRAINZ));
    }

    public function test_no_musicbrainz_match_still_marks_done(): void
    {
        Sleep::fake();
        Http::fake(['musicbrainz.org/*' => Http::response(['recordings' => []])]);
        $track = $this->track();

        (new EnrichTrackMusicBrainz($track))->handle();

        $this->assertTrue(Enrichment::isDone($track, Enrichment::MUSICBRAINZ));
        $this->assertSame(0, Metadata::where('key', 'mbid')->count());
    }

    public function test_lyrics_job_stores_lyrics_or_an_empty_marker(): void
    {
        Http::fake([
            'lrclib.net/*' => Http::sequence()
                ->push(['plainLyrics' => 'la la', 'syncedLyrics' => '[00:01.00] la la'])
                ->push('', 404),
        ]);
        $found = $this->track('Found');
        $missing = $this->track('Missing');

        (new EnrichTrackLyrics($found))->handle();
        (new EnrichTrackLyrics($missing))->handle();

        $this->assertSame('la la', $found->lyricsPlain());
        $this->assertNull($missing->lyricsPlain());
        $this->assertTrue(Enrichment::isDone($found, Enrichment::LRCLIB));
        $this->assertTrue(Enrichment::isDone($missing, Enrichment::LRCLIB));
    }

    public function test_enrich_command_respects_the_limit_and_dry_run(): void
    {
        Queue::fake();
        foreach (['A', 'B', 'C'] as $name) {
            $this->track($name);
        }

        $this->artisan('library:enrich --dry-run')->expectsOutputToContain('3 track(s)')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('library:enrich --limit=2')->expectsOutputToContain('Queued enrichment for 2 of 3')->assertSuccessful();
        Queue::assertPushed(EnrichTrackMusicBrainz::class, 2);
    }
}
