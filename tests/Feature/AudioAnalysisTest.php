<?php

namespace Tests\Feature;

use App\Domain\Analysis\AnalysisActivity;
use App\Domain\Analysis\AnalysisProgress;
use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Analysis\Stages\EssentiaStage;
use App\Domain\Analysis\Stages\LoudnessStage;
use App\Domain\Analysis\Stages\WaveformStage;
use App\Domain\Analysis\Tools;
use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryMerger;
use App\Domain\Storage\FileIndexer;
use App\Jobs\AnalyzeAudioFile;
use App\Jobs\EnrichTrackMusicBrainz;
use App\Livewire\AnalysisStatus;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/** Audio analysis of the own files: stages, the job, the backlog, what it does for the library, and where it shows. */
class AudioAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const EBUR128 = <<<'TXT'
        [Parsed_ebur128_0 @ 0x1] t: 3.0 TARGET:-23 LUFS M: -20.1 S: -22.0 I: -21.5 LUFS LRA: 0.0 LU FTPK: -1.0 dBFS TPK: -1.0 dBFS
        [Parsed_ebur128_0 @ 0x1] Summary:

          Integrated loudness:
            I:         -14.1 LUFS
            Threshold: -24.2 LUFS

          Loudness range:
            LRA:         6.4 LU
            Threshold:  -34.2 LUFS
            LRA low:    -18.0 LUFS
            LRA high:   -11.6 LUFS

          True peak:
            Peak:        -0.5 dBFS
        TXT;

    /** What the whole-file pass of the quality stage prints (stereo, 24 s, lossless, a little clipping, silent start). */
    private const QUALITY = <<<'TXT'
        [silencedetect @ 0x1] silence_start: 0
        [silencedetect @ 0x1] silence_end: 1.5 | silence_duration: 1.5
        [astats@side @ 0x2] Overall
        [astats@side @ 0x2] RMS level dB: -24.0
        [astats@mid @ 0x3] Overall
        [astats@mid @ 0x3] RMS level dB: -20.5
        [astats@all @ 0x4] Overall
        [astats@all @ 0x4] Flat factor: 3.5
        [astats@all @ 0x4] Peak count: 120.000000
        [astats@all @ 0x4] Bit depth: 22/24/24/24
        [astats@all @ 0x4] Number of samples: 1058400
        [out#0/null @ 0x5] video:0KiB audio:8269KiB subtitle:0KiB other streams:0KiB global headers:0KiB muxing overhead: unknown
        TXT;

    /** 8 samples of mono PCM: a quiet half and a loud half. */
    private static function pcm(): string
    {
        return pack('s*', 100, -200, 300, -400, 5000, -10000, 20000, -20000);
    }

    /** Mono float PCM at 44.1 kHz whose spectrum stops at $cutoff Hz: equal sines every 150 Hz up to it. */
    public static function floats(int $cutoff, int $samples = 24000): string
    {
        $freqs = range(150, $cutoff, 150);
        $pcm = '';
        for ($i = 0; $i < $samples; $i++) {
            $sum = 0.0;
            foreach ($freqs as $k => $f) {
                $sum += sin(2 * M_PI * $f * $i / 44100 + $k);
            }
            $pcm .= pack('g', $sum / count($freqs) * 0.5);
        }

        return $pcm;
    }

    private string $dir;

    private bool $ffmpegFails = false;

    /** @var list<list<string>> the commands of the ffmpeg decode runs */
    private array $decodes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tools::forget();
        Http::preventStrayRequests();
        config(['analysis.enabled' => true, 'analysis.acoustid_key' => 'key', 'analysis.essentia_url' => null]);

        $this->dir = sys_get_temp_dir().'/remoment-analysis-'.uniqid();
        mkdir($this->dir);

        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return match (true) {
                str_contains($command, '-version') => Process::result("tool version 1.0\n"),
                str_contains($command, '-filter_complex') => $this->decodePass($process),
                str_contains($command, 'fpcalc') => Process::result(json_encode(['duration' => 241.3, 'fingerprint' => 'AQADtEmS'])),
                str_contains($command, 'f32le') => Process::result(self::floats(16000)),
                default => Process::result('', "unexpected: {$command}", 1),
            };
        });
    }

    /** The shared ffmpeg decode: logs what the requested filters print and writes the audio the branches output. */
    private function decodePass(PendingProcess $process): mixed
    {
        $this->decodes[] = $command = (array) $process->command;
        $line = implode(' ', $command);

        if ($this->ffmpegFails && str_contains($line, 'ebur128')) {
            return Process::result('', 'song.flac: Invalid data found when processing input', 1);
        }

        foreach ($command as $i => $argument) {
            if ($argument === '-f' && ($command[$i + 1] ?? null) === 's16le') {
                file_put_contents($command[$i + 2], self::pcm());
            } elseif ($argument === '-f' && ($command[$i + 1] ?? null) === 'wav') {
                file_put_contents($command[$i + 2], 'RIFF');
            }
        }

        return Process::result('', (str_contains($line, 'ebur128') ? self::EBUR128 : '').(str_contains($line, 'astats') ? self::QUALITY : ''));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        Tools::forget();

        parent::tearDown();
    }

    /** @return array{0: File, 1: Track} an indexed local file with its track */
    private function localTrack(string $name = 'Song', string $hash = 'h1', ?Album $album = null): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->dir], 'role' => 'library']);
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album ??= Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);

        file_put_contents("{$this->dir}/{$name}.flac", 'not really audio');
        $file = File::create([
            'storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $hash, 'size' => 16, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => 'flac',
        ]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $name, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        Enrichment::save($track, 'dlna_url', url('/stream/'.$track->id), 'url', FileIndexer::URL_SOURCE);

        return [$file, $track];
    }

    private function fakeAcoustId(float $score = 0.97): void
    {
        Http::fake(['api.acoustid.org/*' => Http::response([
            'status' => 'ok',
            'results' => [['id' => 'ac-1', 'score' => $score, 'recordings' => [['id' => 'rec-1']]]],
        ])]);
    }

    public function test_loudness_summary_is_parsed_from_the_last_block(): void
    {
        $this->assertSame(
            ['loudness_lufs' => -14.1, 'loudness_range' => 6.4, 'true_peak' => -0.5],
            LoudnessStage::parseSummary(self::EBUR128),
        );

        $this->expectException(\RuntimeException::class);
        LoudnessStage::parseSummary("Summary:\n\n  Integrated loudness:\n    I:         -inf LUFS\n");
    }

    public function test_the_job_runs_every_stage_and_the_library_uses_the_result(): void
    {
        $this->fakeAcoustId();
        [$file, $track] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $analysis = $file->analysis()->first();
        $this->assertSame('h1', $analysis->content_hash);
        $this->assertSame(-14.1, $analysis->loudness_lufs);
        $this->assertSame(6.4, $analysis->loudness_range);
        $this->assertSame('AQADtEmS', $analysis->fingerprint);
        $this->assertSame(241, $analysis->fingerprint_duration);
        $this->assertSame('rec-1', $analysis->acoustid_recording_mbid);
        $this->assertEqualsCanonicalizing(['loudness', 'waveform', 'quality', 'fingerprint', 'acoustid'], array_keys($analysis->stages), 'no Essentia sidecar configured');
        $this->assertSame([], $analysis->errors);

        // The quality stage: the whole-file pass and the spectrum of the excerpt (a 16 kHz low-pass in a lossless file).
        $this->assertSame(120, $analysis->clipped_samples);
        $this->assertTrue($analysis->clipping_suspect);
        $this->assertFalse($analysis->is_mono);
        $this->assertSame(1500, $analysis->lead_silence_ms);
        $this->assertSame(24, $analysis->measured_bit_depth);
        $this->assertGreaterThanOrEqual(0.9, $analysis->lossless_suspect);
        $this->assertGreaterThan(15000, $analysis->spectral_cutoff_hz);
        $this->assertLessThan(18000, $analysis->spectral_cutoff_hz);

        // The recording id becomes the track's mbid, from the source "acoustid".
        $this->assertSame('rec-1', $track->metadata()->where('key', 'mbid')->where('source', 'acoustid')->value('value'));
        $this->assertSame('rec-1', $track->recordingMbid());
        $this->assertSame(0, AudioAnalysis::pending()->count());
    }

    public function test_without_fpcalc_the_lookup_is_skipped_instead_of_failing(): void
    {
        Process::fake(fn (PendingProcess $process) => match (true) {
            str_contains(implode(' ', (array) $process->command), 'fpcalc') => Process::result('', 'not found', 127),
            str_contains(implode(' ', (array) $process->command), '-filter_complex') => $this->decodePass($process),
            str_contains(implode(' ', (array) $process->command), 'f32le') => Process::result(self::floats(16000)),
            default => Process::result("tool version 1.0\n"),
        });
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $analysis = $file->analysis()->first();
        $this->assertSame([], $analysis->errors, 'no "no fingerprint" error');
        $this->assertSame(['loudness', 'waveform', 'quality'], array_keys($analysis->stages));
    }

    public function test_a_weak_acoustid_match_is_no_match(): void
    {
        $this->fakeAcoustId(0.5);
        [$file, $track] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $analysis = $file->analysis()->first();
        $this->assertNull($analysis->acoustid_recording_mbid);
        $this->assertNotNull($analysis->stageVersion('acoustid'), 'an answer without a match is a result');
        $this->assertSame(0, $track->metadata()->where('source', 'acoustid')->count());
    }

    public function test_a_failing_stage_does_not_stop_the_others_and_is_retried_until_it_gives_up(): void
    {
        $this->fakeAcoustId();
        $this->ffmpegFails = true;
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $analysis = $file->analysis()->first();
        $this->assertNull($analysis->loudness_lufs);
        $this->assertStringContainsString('could not decode', $analysis->errors['loudness']['message']);
        $this->assertSame(1, $analysis->errors['loudness']['attempts']);
        $this->assertSame('rec-1', $analysis->acoustid_recording_mbid, 'the fingerprint stages still ran');

        $this->assertSame(1, AudioAnalysis::pending()->count(), 'the failed stage is still to do');

        AnalyzeAudioFile::dispatchSync($file);
        AnalyzeAudioFile::dispatchSync($file);
        $this->assertSame(3, $file->analysis()->first()->errors['loudness']['attempts']);
        $this->assertSame(0, AudioAnalysis::pending()->count(), 'after max_attempts the backlog leaves it alone');

        $this->ffmpegFails = false;
        $analysis = $file->analysis()->first();
        $analysis->forceFill(['stages' => array_merge($analysis->stages, ['loudness' => ['version' => '0']])]);
        $this->assertTrue($analysis->gaveUp('loudness', '1'));
    }

    public function test_a_stage_version_change_and_a_changed_file_bring_a_file_back(): void
    {
        $this->fakeAcoustId();
        [$file] = $this->localTrack();
        AnalyzeAudioFile::dispatchSync($file);
        $this->assertSame(0, AudioAnalysis::pending()->count());

        $analysis = $file->analysis()->first();
        $stages = $analysis->stages;
        $stages['loudness']['version'] = '0';
        $analysis->update(['stages' => $stages]);
        $this->assertSame(1, AudioAnalysis::pending(only: 'loudness')->count());
        $this->assertSame(0, AudioAnalysis::pending(only: 'fingerprint')->count(), 'only that stage is stale');

        AnalyzeAudioFile::dispatchSync($file, 'loudness');
        $this->assertSame(0, AudioAnalysis::pending()->count());

        $file->update(['hash' => 'changed']);
        $this->assertSame(1, AudioAnalysis::pending()->count());

        AnalyzeAudioFile::dispatchSync($file->fresh());
        $this->assertSame('changed', $file->analysis()->first()->content_hash);
    }

    public function test_missing_files_and_files_without_a_track_are_not_analysed(): void
    {
        [$gone] = $this->localTrack('Gone', 'h-gone');
        $gone->update(['status' => File::MISSING]);
        $orphan = File::create(['storage_location_id' => $gone->storage_location_id, 'path' => 'orphan.flac', 'hash' => 'h2', 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);

        $this->assertSame(0, AudioAnalysis::pending()->count());
        $this->assertNotNull($orphan);
    }

    public function test_the_whole_file_is_decoded_once_for_all_stages(): void
    {
        config(['analysis.essentia_url' => 'http://analysis:8090']);
        Http::fake(['analysis:8090/analyze' => Http::response(['bpm' => 100]), 'api.acoustid.org/*' => Http::response(['status' => 'ok', 'results' => []])]);
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $this->assertCount(1, $this->decodes, 'loudness, waveform, quality and Essentia share one ffmpeg run');
        $line = implode(' ', $this->decodes[0]);
        foreach (['ebur128', 'astats@all', 's16le', '-f wav', 'asplit=4'] as $part) {
            $this->assertStringContainsString($part, $line);
        }
        $this->assertSame([], $file->analysis()->first()->errors);
        $this->assertSame([], glob(sys_get_temp_dir().'/remoment-decode-*'), 'temp files are removed');
    }

    public function test_a_stage_on_its_own_still_decodes_for_itself(): void
    {
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file, 'loudness');

        $this->assertCount(1, $this->decodes);
        $this->assertStringNotContainsString('asplit=4[i', implode(' ', $this->decodes[0]));
        $this->assertSame(-14.1, $file->analysis()->first()->loudness_lufs);
    }

    public function test_essentia_results_are_stored_and_flats_become_sharps(): void
    {
        config(['analysis.essentia_url' => 'http://analysis:8090']);
        Http::fake([
            'analysis:8090/analyze' => Http::response([
                'bpm' => 123.46, 'key' => 'Bb', 'mode' => 'minor', 'key_strength' => 0.8, 'danceability' => 1.4,
                'mood_tags' => [['name' => 'happy', 'score' => 0.91234]], 'genre_tags' => [['name' => 'Trip Hop', 'score' => 0.4]],
            ]),
            'api.acoustid.org/*' => Http::response(['status' => 'ok', 'results' => []]),
        ]);
        [$file, $track] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $analysis = $file->analysis()->first();
        $this->assertSame(123.5, $analysis->bpm);
        $this->assertSame('A#', $analysis->key);
        $this->assertSame('minor', $analysis->mode);
        $this->assertSame(1.0, $analysis->danceability, 'clamped to 0–1');
        $this->assertSame([['name' => 'happy', 'score' => 0.912]], $analysis->mood_tags);
        $this->assertSame('1', $analysis->stageVersion('essentia'));
        Http::assertSent(fn ($request) => $request->url() === 'http://analysis:8090/analyze');

        // Classifier genres only stand in while the track has none.
        $this->assertSame(['Trip Hop'], $track->genres());
    }

    public function test_the_ml_flag_is_part_of_the_essentia_version(): void
    {
        $this->assertSame('1', (new EssentiaStage)->version());
        config(['analysis.essentia_ml' => true]);
        $this->assertSame('1+ml', (new EssentiaStage)->version());
    }

    public function test_mood_tags_under_the_cut_off_are_dropped(): void
    {
        config(['analysis.mood_min_score' => 0.7]);

        $attributes = EssentiaStage::attributes(['mood_tags' => [
            ['name' => 'party', 'score' => 0.83], ['name' => 'aggressive', 'score' => 0.62], ['name' => 'happy', 'score' => 0.7],
        ]]);

        $this->assertSame([['name' => 'party', 'score' => 0.83], ['name' => 'happy', 'score' => 0.7]], $attributes['mood_tags']);
        $this->assertArrayNotHasKey('mood_tags', EssentiaStage::attributes(['mood_tags' => [['name' => 'sad', 'score' => 0.55]]]));

        // Genres keep every score the sidecar sent.
        $this->assertSame([['name' => 'Rock', 'score' => 0.2]], EssentiaStage::attributes(['genre_tags' => [['name' => 'Rock', 'score' => 0.2]]])['genre_tags']);
    }

    public function test_features_the_sidecar_did_not_compute_are_left_out(): void
    {
        $this->assertSame(['bpm' => 120.0], EssentiaStage::attributes(['bpm' => 120, 'mode' => 'weird', 'mood_tags' => []]));
    }

    public function test_the_command_queues_on_the_analysis_queue_and_respects_the_switch(): void
    {
        Queue::fake();
        [$a] = $this->localTrack('A', 'ha');
        $this->localTrack('B', 'hb');

        config(['analysis.enabled' => false]);
        $this->artisan('library:analyze')->expectsOutputToContain('Audio analysis is off')->assertSuccessful();
        Queue::assertNothingPushed();

        config(['analysis.enabled' => true]);
        $this->artisan('library:analyze --dry-run')->expectsOutputToContain('2 file(s) still need analysis')->assertSuccessful();

        $this->artisan('library:analyze --limit=1')->expectsOutputToContain('Queued analysis of 1 file(s)')->assertSuccessful();
        Queue::assertPushedOn('analysis', AnalyzeAudioFile::class);
        Queue::assertPushed(AnalyzeAudioFile::class, 1);

        $this->artisan('library:analyze --stage=nonsense')->assertFailed();
        $this->assertNotNull($a);
    }

    public function test_analysis_shows_in_track_details_the_api_and_the_album_page(): void
    {
        [$file, $track] = $this->localTrack();
        FileAnalysis::create([
            'file_id' => $file->id, 'content_hash' => 'h1', 'bpm' => 124.0, 'key' => 'A', 'mode' => 'minor', 'loudness_lufs' => -9.2,
            'danceability' => 0.8, 'instrumental' => 0.9, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.7]], 'acoustid_recording_mbid' => 'rec-1',
        ]);

        $sound = $track->fresh()->sound();
        $this->assertSame(124.0, $sound['bpm']);
        $this->assertSame(['relaxed'], $sound['mood']);
        $this->assertSame('rec-1', $sound['recording_mbid']);

        $this->getJson("/api/library/albums/{$track->album_id}")->assertOk()
            ->assertJsonPath('tracks.0.details.analysis.bpm', 124)
            ->assertJsonPath('tracks.0.details.analysis.key', 'A')
            ->assertJsonPath('tracks.0.details.analysis.loudness', -9.2);

        $this->get("/albums/{$track->album_id}")->assertOk()
            ->assertSee('124 BPM · A minor (8A) · -9.2 LUFS')->assertSee('relaxed')->assertSee('80%')->assertSee('instrumental');

        $other = Track::create(['artist_id' => $track->artist_id, 'album_id' => $track->album_id, 'name' => 'Unanalysed', 'source' => 'dlna']);
        $this->assertNull($other->sound());
    }

    public function test_recordings_with_the_same_acoustid_merge_and_different_ones_stay_apart(): void
    {
        [$fileA, $a] = $this->localTrack('Alpha', 'ha');
        [$fileB, $b] = $this->localTrack('Beta', 'hb');
        [, $c] = $this->localTrack('Gamma', 'hc');
        [, $d] = $this->localTrack('Gamma2', 'hd');
        $d->update(['name' => 'Gamma']);
        $c->update(['name' => 'Gamma']);
        foreach ([[$a, 'rec-1'], [$b, 'rec-1'], [$c, 'rec-live'], [$d, 'rec-studio']] as [$track, $mbid]) {
            Enrichment::save($track, 'mbid', $mbid, 'string', Enrichment::ACOUSTID);
        }

        $merger = new LibraryMerger;
        $plan = $merger->plan();
        $this->assertSame([[$a->id, [$b->id]]], $plan['tracks'], 'one recording under two names merges; one name with two recordings does not');

        $merger->merge($plan);

        $this->assertNull(Track::find($b->id));
        $this->assertNotNull(Track::find($c->id));
        $this->assertNotNull(Track::find($d->id));
    }

    public function test_a_merged_track_keeps_its_files_and_stream_url(): void
    {
        $spotify = Track::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'spotify'])->id, 'name' => 'Alpha', 'duration' => 200, 'source' => 'spotify', 'external_id' => 'spotify:track:a']);
        [$file, $local] = $this->localTrack('Alpha', 'ha');
        $this->assertGreaterThan($spotify->id, $local->id);

        $merger = new LibraryMerger;
        $merger->mergeTracks($spotify->id, [$local->id]);

        $link = TrackFile::where('file_id', $file->id)->first();
        $this->assertSame($spotify->id, $link->track_id, 'the file is not lost with its track');
        $this->assertTrue($link->is_primary);
        $this->assertSame(url('/stream/'.$spotify->id), $spotify->metadata()->where('key', 'dlna_url')->value('value'));
    }

    public function test_the_musicbrainz_job_looks_the_recording_up_directly_when_acoustid_named_it(): void
    {
        Sleep::fake();
        Queue::fake();
        Http::fake([
            'musicbrainz.org/ws/2/recording/rec-1*' => Http::response([
                'isrcs' => ['NLA000000001'],
                'artist-credit' => [['artist' => ['id' => 'art-1']]],
                'releases' => [['id' => 'rel-1', 'title' => 'LP']],
            ]),
        ]);
        [, $track] = $this->localTrack();
        Enrichment::save($track, 'mbid', 'rec-1', 'string', Enrichment::ACOUSTID);

        (new EnrichTrackMusicBrainz($track))->handle();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'query='));
        $this->assertSame('NLA000000001', $track->metadata()->where('key', 'isrc')->value('value'));
        $this->assertSame('art-1', $track->artist->metadata()->where('key', 'mbid')->value('value'));
        $this->assertSame('rel-1', $track->album->metadata()->where('key', 'mbid')->value('value'));
    }

    public function test_peaks_are_the_loudest_sample_of_each_part_scaled_to_100(): void
    {
        $this->assertSame([1, 2, 50, 100], WaveformStage::peaks(self::pcm(), 4));
        $this->assertSame([100], WaveformStage::peaks(self::pcm(), 1));
        $this->assertCount(8, WaveformStage::peaks(self::pcm(), 400), 'never more points than samples');

        $this->expectException(\RuntimeException::class);
        WaveformStage::peaks('', 4);
    }

    public function test_the_waveform_is_stored_and_served_by_the_api_and_drawn_on_the_album_page(): void
    {
        config(['analysis.waveform_points' => 4]);
        $this->fakeAcoustId();
        [$file, $track] = $this->localTrack();
        $this->getJson("/api/library/tracks/{$track->id}/waveform")->assertNotFound();

        AnalyzeAudioFile::dispatchSync($file);

        $stored = $file->analysis()->first();
        $this->assertSame([1, 2, 50, 100], $stored->waveform_peak);
        $this->assertCount(4, $stored->waveform);
        $this->assertSame($stored->waveform, $track->fresh()->waveform());
        $this->getJson("/api/library/tracks/{$track->id}/waveform")->assertOk()
            ->assertJsonPath('points', $stored->waveform)->assertJsonPath('duration', 200);

        $this->get("/albums/{$track->album_id}")->assertOk()->assertSee('aria-label="Waveform"', false)->assertSee('Waveform');
    }

    public function test_progress_counts_done_failed_waiting_and_given_up_per_stage(): void
    {
        $this->fakeAcoustId();
        [$done] = $this->localTrack('Done', 'h1');
        [$broken] = $this->localTrack('Broken', 'h2');
        $this->localTrack('Untouched', 'h3');

        AnalyzeAudioFile::dispatchSync($done);
        $this->ffmpegFails = true;
        AnalyzeAudioFile::dispatchSync($broken);

        $progress = AnalysisProgress::compute();
        $this->assertSame(3, $progress['files']);
        $this->assertSame(1, $progress['complete']);
        $this->assertSame(1, $progress['with_problems']);
        $this->assertSame(2, $progress['backlog'], 'the failed file and the untouched one');
        $this->assertEquals(['done' => 1, 'failed' => 1, 'pending' => 1, 'gave_up' => 0], array_intersect_key($progress['stages']['loudness'], array_flip(['done', 'failed', 'pending', 'gave_up'])));
        $this->assertFalse($progress['stages']['essentia']['enabled']);
        $this->assertStringContainsString('ANALYSIS_ESSENTIA_URL', $progress['stages']['essentia']['reason']);

        AnalyzeAudioFile::dispatchSync($broken);
        AnalyzeAudioFile::dispatchSync($broken);
        $progress = AnalysisProgress::compute();
        $this->assertSame(1, $progress['stages']['loudness']['gave_up']);
        $this->assertSame(0, $progress['stages']['loudness']['failed']);
        $this->assertSame(1, $progress['backlog'], 'a given-up stage no longer counts as waiting');
    }

    public function test_a_bumped_stage_version_counts_as_waiting_and_stale(): void
    {
        $this->fakeAcoustId();
        [$file] = $this->localTrack();
        AnalyzeAudioFile::dispatchSync($file);

        $analysis = $file->analysis()->first();
        $analysis->forceFill(['stages' => array_merge($analysis->stages, ['quality' => ['version' => '0', 'at' => now()->toIso8601String()]])])->save();

        $quality = AnalysisProgress::compute()['stages']['quality'];
        $this->assertSame(0, $quality['done']);
        $this->assertSame(1, $quality['pending']);
        $this->assertSame(1, $quality['stale']);
    }

    public function test_the_job_leaves_a_timestamp_and_stage_timings_and_no_running_marker(): void
    {
        $this->fakeAcoustId();
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file);

        $this->assertNotNull($file->analysis()->first()->analyzed_at);
        $this->assertSame(1, AnalysisProgress::filesLastDay());
        $this->assertNull(AnalysisActivity::current());
        $this->assertNotNull(AnalysisActivity::averageSeconds('loudness'));
    }

    public function test_a_running_stage_is_shown_until_it_finishes(): void
    {
        [$file] = $this->localTrack();

        AnalysisActivity::start($file, 'quality');
        $this->assertSame('quality', AnalysisActivity::current()['stage']);

        AnalysisActivity::finish();
        $this->assertNull(AnalysisActivity::current());
    }

    public function test_the_status_page_needs_the_admin_login_and_shows_stages_and_problems(): void
    {
        $this->fakeAcoustId();
        [$file] = $this->localTrack('Broken');
        $this->ffmpegFails = true;
        AnalyzeAudioFile::dispatchSync($file);
        AnalysisProgress::forget();

        $this->get('/settings/analysis')->assertRedirect();

        $this->actingAs(User::factory()->create());
        $this->get('/settings/analysis')->assertOk()->assertSee('Audio analysis');
        $this->get('/settings')->assertOk()->assertSee('Audio analysis');

        Livewire::test(AnalysisStatus::class)
            ->assertSee('Stages')
            ->assertSeeHtml('data-test="stage-loudness"')
            ->assertSeeHtml('data-test="stage-disabled-essentia"')
            ->assertSee('could not decode')
            ->assertSeeHtml("data-test=\"problem-{$file->id}-loudness\"");
    }

    public function test_retry_forgets_the_error_and_queues_only_that_stage(): void
    {
        $this->fakeAcoustId();
        [$file] = $this->localTrack();
        $this->ffmpegFails = true;
        AnalyzeAudioFile::dispatchSync($file);
        $this->assertArrayHasKey('loudness', $file->analysis()->first()->errors);

        Queue::fake();
        $this->actingAs(User::factory()->create());
        Livewire::test(AnalysisStatus::class)->call('retry', $file->id, 'loudness');

        $this->assertArrayNotHasKey('loudness', $file->analysis()->first()->errors);
        Queue::assertPushed(AnalyzeAudioFile::class, fn ($job) => $job->file->is($file) && $job->only === 'loudness');
    }

    public function test_the_status_page_says_when_analysis_is_off(): void
    {
        config(['analysis.enabled' => false]);
        $this->actingAs(User::factory()->create());

        Livewire::test(AnalysisStatus::class)->assertSeeHtml('data-test="analysis-off"');
    }

    public function test_per_file_stage_states_for_the_file_list_and_track_panel(): void
    {
        $this->fakeAcoustId();
        [$file] = $this->localTrack();
        $this->assertSame('pending', AnalysisProgress::forFile(null)['loudness']['state']);

        AnalyzeAudioFile::dispatchSync($file);
        $states = AnalysisProgress::forFile($file->analysis()->first(), $file->hash);
        $this->assertSame('done', $states['loudness']['state']);
        $this->assertNotNull($states['loudness']['at']);

        $this->assertSame('pending', AnalysisProgress::forFile($file->analysis()->first(), 'another-hash')['loudness']['state'], 'a changed file starts over');
    }

    public function test_the_album_page_says_when_a_track_is_not_analysed_yet(): void
    {
        [, $track] = $this->localTrack();

        $this->get("/albums/{$track->album_id}")->assertOk()->assertSee('Not analysed yet');
    }

    public function test_the_nightly_run_queues_what_fits_in_the_time_window(): void
    {
        config(['analysis.batch' => 100, 'analysis.night_hours' => 6, 'analysis.essentia_url' => 'http://analysis:8090']);
        $this->assertNull(AnalysisActivity::secondsPerFile());
        $this->assertSame(100, AudioAnalysis::nightlyLimit(), 'nothing measured yet: the fixed batch');

        AnalysisActivity::finish('decode', 20);
        AnalysisActivity::finish('loudness', 10);
        AnalysisActivity::finish('essentia', 40);
        $this->assertSame(70.0, AnalysisActivity::secondsPerFile());
        $this->assertSame(308, AudioAnalysis::nightlyLimit(), '6 h = 21600 s at 70 s a file');

        config(['analysis.night_hours' => 1]);
        $this->assertSame(51, AudioAnalysis::nightlyLimit());
    }
}
