<?php

namespace Tests\Feature;

use App\Domain\Health\LibraryReports;
use App\Domain\Library\LeadingSource;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The library health reports, their page and their counts API. */
class LibraryHealthTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Album $album;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        LibraryReports::forget();
        $this->album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'dlna'])->id, 'name' => 'LP', 'source' => 'dlna']);
    }

    /** @return array{0: File, 1: Track} */
    private function file(string $name, array $analysis = [], string $extension = 'flac', array $file = [], ?Album $album = null): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        $track = $this->makeLibraryTrack($album ?? $this->album, $name);
        $file = File::create($file + [
            'storage_location_id' => $location->id, 'path' => "{$name}.{$extension}", 'hash' => $name.++$this->n, 'size' => 5242880, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => $extension, 'bitrate' => 320,
        ]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        if ($analysis !== []) {
            FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $file->hash] + $analysis);
        }

        return [$file, $track];
    }

    private function titles(string $report): array
    {
        return LibraryReports::rows($report)->pluck('title')->all();
    }

    public function test_analysis_findings(): void
    {
        $this->file('Fake', ['lossless_suspect' => 0.95, 'spectral_cutoff_hz' => 16500]);
        $this->file('Weak', ['lossless_suspect' => 0.3, 'spectral_cutoff_hz' => 20500]);
        $this->file('Broken', ['decode_warnings' => ['Header missing']]);
        $this->file('Clean', ['decode_warnings' => null, 'clipping_suspect' => false]);
        $this->file('Loud', ['clipping_suspect' => true, 'clipped_samples' => 4321]);

        $this->assertSame(['Band · Fake'], $this->titles('transcodes'));
        $this->assertSame('FLAC, spectrum ends at 16.5 kHz (95% sure)', LibraryReports::rows('transcodes')->first()['detail']);
        $this->assertSame(['Band · Broken'], $this->titles('damaged'));
        $this->assertSame('Header missing', LibraryReports::rows('damaged')->first()['detail']);
        $this->assertSame(['Band · Loud'], $this->titles('clipping'));
        $this->assertSame('4,321 peaks at the ceiling', LibraryReports::rows('clipping')->first()['detail']);
    }

    public function test_hidden_and_missing_files_do_not_count_as_findings(): void
    {
        $this->file('Hidden', ['lossless_suspect' => 0.95, 'spectral_cutoff_hz' => 16500], 'flac', ['hidden_at' => now()]);
        $this->file('Gone', ['lossless_suspect' => 0.95, 'spectral_cutoff_hz' => 16500], 'flac', ['status' => File::MISSING]);

        $this->assertSame([], $this->titles('transcodes'));
        $this->assertSame(['Gone.flac'], $this->titles('missing'));
    }

    public function test_lossy_files_come_worst_spectrum_first(): void
    {
        $this->file('Good', ['spectral_cutoff_hz' => 20000], 'mp3', ['bitrate' => 320000]);
        $this->file('Bad', ['spectral_cutoff_hz' => 16000], 'mp3', ['bitrate' => 128000]);
        $this->file('Unknown', [], 'm4a');
        $this->file('Lossless', ['spectral_cutoff_hz' => 16000], 'flac');

        $rows = LibraryReports::rows('lossy');

        $this->assertSame(['Band · Bad', 'Band · Good', 'Band · Unknown'], $rows->pluck('title')->all());
        $this->assertSame('MP3 · 128 kbps · spectrum ends at 16.0 kHz', $rows[0]['detail'], 'bits per second are shown as kbps');
        $this->assertSame(3, LibraryReports::count('lossy'));
    }

    public function test_the_same_recording_in_two_files_is_a_duplicate_with_the_best_file_first(): void
    {
        $this->file('Song', ['acoustid_recording_mbid' => 'rec-1'], 'mp3', ['bitrate' => 128000]);
        [, $track] = $this->file('Song (flac)', ['acoustid_recording_mbid' => 'rec-1'], 'flac', ['bit_depth' => 16]);
        $this->file('Other', ['acoustid_recording_mbid' => 'rec-2']);

        $this->assertSame(1, LibraryReports::count('duplicates'));

        $row = LibraryReports::rows('duplicates')->first();
        $this->assertSame('Band · Song (flac)', $row['title']);
        $this->assertStringStartsWith('Song (flac).flac (FLAC', $row['detail']);
        $this->assertStringContainsString("\nSong.mp3 (MP3 · 128 kbps", $row['detail']);
        $this->assertSame(route('albums.show', $track->album_id), $row['url']);
    }

    public function test_an_album_with_fewer_tracks_than_musicbrainz_lists_is_incomplete(): void
    {
        $this->file('One');
        $this->file('Two');
        $this->tag($this->album, 12);

        $full = Album::create(['artist_id' => $this->album->artist_id, 'name' => 'Complete', 'source' => 'dlna']);
        $this->file('Only', [], 'flac', [], $full);
        $this->tag($full, 1);

        $empty = Album::create(['artist_id' => $this->album->artist_id, 'name' => 'Streaming only', 'source' => 'dlna']);
        $this->tag($empty, 10);

        $rows = LibraryReports::rows('incomplete');

        $this->assertSame(['Band · LP'], $rows->pluck('title')->all());
        $this->assertSame('2 of 12 tracks', $rows[0]['detail']);
        $this->assertSame(1, LibraryReports::count('incomplete'));
    }

    private function tag(Album $album, int $tracks): void
    {
        Metadata::create(['metadatable_type' => (new Album)->getMorphClass(), 'metadatable_id' => $album->id, 'key' => 'track_count', 'value' => (string) $tracks, 'type' => 'string', 'source' => 'musicbrainz']);
    }

    public function test_a_file_no_track_plays_is_unlinked(): void
    {
        $location = StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        File::create(['storage_location_id' => $location->id, 'path' => 'stray.flac', 'hash' => 'stray', 'size' => 2048, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
        $this->file('Linked');

        $rows = LibraryReports::rows('unlinked');

        $this->assertSame(['stray.flac'], $rows->pluck('title')->all());
        $this->assertSame('2 kB', $rows[0]['detail']);
    }

    public function test_the_page_lists_the_reports_with_their_counts(): void
    {
        $this->file('Fake', ['lossless_suspect' => 0.95, 'spectral_cutoff_hz' => 16500]);

        $this->get('/settings/library-health')->assertRedirect();

        $this->actingAs(User::factory()->create());
        $this->get('/settings/library-health')->assertOk()->assertSee('Suspected transcodes')->assertSee('Incomplete albums');
        $this->get('/settings/library-health/transcodes')->assertOk()->assertSee('Band · Fake')->assertSee('spectrum ends at 16.5 kHz');
        $this->get('/settings/library-health/damaged')->assertOk()->assertSee('Nothing to report');
        $this->get('/settings/library-health/nope')->assertNotFound();
        $this->get('/settings')->assertOk()->assertSee('Library Health');
    }

    public function test_the_api_gives_the_counts(): void
    {
        $this->file('Fake', ['lossless_suspect' => 0.95, 'spectral_cutoff_hz' => 16500]);

        $response = $this->getJson('/api/library/health')->assertOk();

        $this->assertSame(array_keys(LibraryReports::definitions()), array_column($response->json('data'), 'key'));
        $this->assertSame(['key' => 'transcodes', 'title' => 'Suspected transcodes', 'description' => LibraryReports::definitions()['transcodes']['description'], 'count' => 1], $response->json('data.0'));
        $this->assertSame(0, $response->json('data.1.count'));
    }
}
