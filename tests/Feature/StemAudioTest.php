<?php

namespace Tests\Feature;

use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\StemAudio;
use App\Jobs\AnalyzeTrackDeep;
use App\Models\AdminToken;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** The stems of one track kept as audio files (kind `stem_audio`): stage, files, signed download, remove, prune. */
class StemAudioTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = [
        'version' => 1, 'duration' => 3.0, 'method' => 'htdemucs', 'rate_hz' => 2,
        'stems' => [
            'vocals' => [-80, -30, -29, -31, -80, -80],
            'drums' => [-20, -21, -20, -22, -20, -19],
            'bass' => [-25, -25, -26, -25, -25, -24],
            'other' => [-40, -41, -39, -42, -40, -45],
        ],
        'vocal_ratio' => 0.5, 'instrumental' => [['start' => 2.0, 'end' => 3.0]],
    ];

    private string $dir;

    private string $token;

    /** @var list<string> the URLs the fake service was asked for */
    private array $asked = [];

    /** @var \Closure|null builds the fake service's answer for the format it was asked for */
    private ?\Closure $answer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('stems');
        Http::preventStrayRequests();
        Http::fake([
            'stems:8092/health' => Http::response(['ok' => true]),
            'stems:8092/stems*' => function ($request) {
                $this->asked[] = $request->url();
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return $this->answer ? ($this->answer)($query['audio'] ?? 'mp3') : self::zip($query['audio'] ?? 'mp3');
            },
        ]);
        \App\Domain\Analysis\Tools::forget();
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result("ffmpeg version 7\n")]);
        config(['analysis.enabled' => true, 'analysis.stems_url' => 'http://stems:8092', 'analysis.structure_url' => null, 'analysis.essentia_url' => null, 'analysis.stem_audio_disk' => 'stems', 'analysis.stem_audio_format' => 'mp3']);

        $this->dir = sys_get_temp_dir().'/remoment-stemaudio-'.uniqid();
        mkdir($this->dir);

        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** The zip the stems service answers: stems.json and one file per stem. */
    private static function zip(string $format, array $drop = [], array $json = [], array $names = ['vocals', 'drums', 'bass', 'other']): \Illuminate\Http\Client\PromiseInterface|\GuzzleHttp\Promise\PromiseInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = [];
        foreach ($names as $stem) {
            $files[$stem] = "{$stem}.{$format}";
            if (!in_array($stem, $drop, true)) {
                $zip->addFromString("{$stem}.{$format}", "audio of {$stem} as {$format}");
            }
        }
        $zip->addFromString('stems.json', json_encode($json + self::SERVICE + ['audio' => ['format' => $format, 'bitrate' => $format === 'mp3' ? 192 : null, 'sample_rate' => 44100, 'channels' => 2, 'files' => $files]]));
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);

        return Http::response($body, 200, ['Content-Type' => 'application/zip']);
    }

    /** @return array{0: File, 1: Track} */
    private function localTrack(string $name = 'Song', string $hash = 'h1'): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->dir], 'role' => 'library']);
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);

        file_put_contents("{$this->dir}/{$name}.flac", 'not really audio');
        $file = File::create([
            'storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $hash, 'size' => 16, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => 'flac',
        ]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $name, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);

        return [$file, $track];
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function runJob(File $file, string $kind = 'stem_audio'): void
    {
        (new AnalyzeTrackDeep($file, $kind))->handle();
    }

    // --- the stage ---------------------------------------------------------------

    /** The six stems of `htdemucs_6s`, in the order the service writes them (the service's lanes follow). */
    private function sixStemAnswer(): \Closure
    {
        $six = ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other'];
        $stems = self::SERVICE['stems'] + ['guitar' => [-30, -30, -31, -30, -29, -30], 'piano' => [-50, -50, -52, -51, -50, -49]];

        return fn (string $format) => self::zip($format, [], ['method' => 'htdemucs_6s', 'stems' => $stems], $six);
    }

    public function test_a_six_stem_run_keeps_guitar_and_piano_and_the_api_lists_all_six_in_display_order(): void
    {
        [$file, $track] = $this->localTrack();
        $this->answer = $this->sixStemAnswer();

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $this->assertSame(['vocals', 'drums', 'bass', 'guitar', 'piano', 'other'], array_keys($analysis->stem_audio['files']));
        $this->assertSame(['vocals', 'drums', 'bass', 'guitar', 'piano', 'other'], array_keys($analysis->stems['lanes']));
        $this->assertSame('htdemucs_6s', $analysis->stems['method']);

        $response = $this->getJson("/api/library/tracks/{$track->id}/stems", $this->auth())->assertOk();
        $this->assertSame(['vocals', 'drums', 'bass', 'guitar', 'piano', 'other'], array_keys($response->json('stems')));
        $this->get($response->json('stems.piano.url'))->assertOk();
        $this->assertSame(6, count(Storage::disk('stems')->files("stems/{$file->id}")));
    }

    public function test_a_service_answer_without_one_of_the_four_required_stems_is_refused_even_with_extras(): void
    {
        [$file] = $this->localTrack();
        $this->answer = fn (string $format) => self::zip($format, ['bass'], [], ['vocals', 'drums', 'bass', 'guitar', 'other']);

        $this->runJob($file);

        $this->assertNull($file->analysis()->first()?->stem_audio);
        $this->assertSame([], Storage::disk('stems')->files("stems/{$file->id}"));
    }

    public function test_the_job_keeps_the_four_files_the_manifest_and_the_lanes_from_one_run(): void
    {
        [$file] = $this->localTrack();

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $this->assertSame('done', DeepAnalysis::state($file, $analysis, 'stem_audio')['state']);
        $this->assertSame('done', DeepAnalysis::state($file, $analysis, 'stems')['state'], 'the same separation gave the lanes');
        $this->assertSame([-80, -30, -29, -31, -80, -80], $analysis->stems['lanes']['vocals']);

        $manifest = $analysis->stem_audio;
        $this->assertSame('mp3', $manifest['format']);
        $this->assertSame(192, $manifest['bitrate']);
        $this->assertSame(44100, $manifest['sample_rate']);
        $this->assertEquals(3.0, $manifest['duration']);
        $this->assertSame(['vocals', 'drums', 'bass', 'other'], array_keys($manifest['files']));
        $this->assertSame(strlen('audio of vocals as mp3'), $manifest['files']['vocals']['bytes']);
        $this->assertSame(array_sum(array_column($manifest['files'], 'bytes')), $manifest['bytes']);

        foreach ($manifest['files'] as $stem => $info) {
            $this->assertSame("audio of {$stem} as mp3", Storage::disk('stems')->get("stems/{$file->id}/{$info['name']}"));
        }

        $this->assertCount(1, $this->asked);
        $this->assertStringContainsString('audio=mp3', $this->asked[0]);
        $this->assertStringContainsString('bitrate=192', $this->asked[0]);
    }

    public function test_the_format_follows_the_setting_and_an_unknown_one_is_mp3(): void
    {
        config(['analysis.stem_audio_format' => 'flac']);
        [$file] = $this->localTrack();

        $this->runJob($file);

        $this->assertSame('flac', $file->analysis()->first()->stem_audio['format']);
        $this->assertTrue(Storage::disk('stems')->exists("stems/{$file->id}/vocals.flac"));
        $this->assertStringContainsString('audio=flac', $this->asked[0]);

        config(['analysis.stem_audio_format' => 'wav']);
        $this->assertSame('mp3', StemAudio::format());
    }

    public function test_a_zip_without_a_stem_is_a_failure_and_leaves_no_files(): void
    {
        $this->answer = fn ($format) => self::zip($format, drop: ['bass']);
        [$file] = $this->localTrack();

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $state = DeepAnalysis::state($file, $analysis, 'stem_audio');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('bass file', $state['message']);
        $this->assertNull($analysis->stem_audio);
        $this->assertSame([], Storage::disk('stems')->allFiles());
    }

    public function test_a_service_error_is_a_failure_with_its_message(): void
    {
        $this->answer = fn () => Http::response(['detail' => 'Cannot encode flac here; this ffmpeg makes: mp3.'], 422);
        [$file] = $this->localTrack();

        $this->runJob($file);

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'stem_audio');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('HTTP 422', $state['message']);
        $this->assertStringContainsString('Cannot encode flac', $state['message']);
    }

    public function test_a_second_run_replaces_the_files(): void
    {
        [$file] = $this->localTrack();
        $this->runJob($file);

        config(['analysis.stem_audio_format' => 'flac']);
        $file->analysis()->first()->forgetStage('stem_audio'); // as if the stage version had moved on
        $analysis = $file->analysis()->first();
        $analysis->forgetStage('stem_audio');
        $analysis->save();
        $this->runJob($file);

        $this->assertSame(['bass.flac', 'drums.flac', 'other.flac', 'vocals.flac'], collect(Storage::disk('stems')->files("stems/{$file->id}"))->map('basename')->sort()->values()->all());
    }

    // --- the request -----------------------------------------------------------------

    public function test_asking_for_the_kind_queues_it_on_the_stems_queue_with_an_estimate(): void
    {
        Queue::fake();
        [$file, $track] = $this->localTrack();

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['stem_audio']], $this->auth())
            ->assertStatus(202)
            ->assertJsonPath('queued', ['stem_audio'])
            ->assertJsonPath('estimate.stem_audio_seconds', 115);

        Queue::assertPushedOn('stems', AnalyzeTrackDeep::class, fn ($job) => $job->file->is($file) && $job->kind === 'stem_audio');
        $this->getJson("/api/library/tracks/{$track->id}/deep-analysis", $this->auth())->assertJsonPath('kinds.stem_audio.state', 'queued');
    }

    // --- the list and the signed download ------------------------------------------

    public function test_the_list_says_not_ready_until_the_files_are_kept(): void
    {
        [, $track] = $this->localTrack();

        $this->getJson("/api/library/tracks/{$track->id}/stems", $this->auth())
            ->assertNotFound()->assertJsonPath('error', 'not_ready')->assertJsonPath('state', 'none');
    }

    public function test_the_list_and_the_request_are_open_and_need_a_track_with_a_file(): void
    {
        [$file, $track] = $this->localTrack();
        $this->runJob($file);

        $this->getJson("/api/library/tracks/{$track->id}/stems")->assertOk()->assertJsonPath('format', 'mp3'); // no token

        $bare = Track::create(['artist_id' => $track->artist_id, 'name' => 'Streamed', 'source' => 'dlna', 'external_id' => 'x:1']);
        $this->getJson("/api/library/tracks/{$bare->id}/stems")->assertStatus(422)->assertJsonPath('error', 'no_file');
    }

    public function test_the_list_gives_signed_urls_and_what_a_mixer_needs_and_each_downloads(): void
    {
        [$file, $track] = $this->localTrack();
        $file->analysis()->create(['content_hash' => 'h1', 'bpm' => 124.4, 'key' => 'A', 'mode' => 'minor']);
        $this->runJob($file);

        $response = $this->getJson("/api/library/tracks/{$track->id}/stems", $this->auth())->assertOk()
            ->assertJsonPath('track_id', $track->id)
            ->assertJsonPath('format', 'mp3')
            ->assertJsonPath('mime', 'audio/mpeg')
            ->assertJsonPath('bitrate', 192)
            ->assertJsonPath('sample_rate', 44100)
            ->assertJsonPath('channels', 2)
            ->assertJsonPath('duration', 3)
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonPath('bpm', 124.4)
            ->assertJsonPath('key', 'A')
            ->assertJsonPath('camelot', '8A');
        $this->assertSame(['vocals', 'drums', 'bass', 'other'], array_keys($response->json('stems')));
        $this->assertSame(strlen('audio of vocals as mp3'), $response->json('stems.vocals.bytes'));

        foreach ($response->json('stems') as $stem => $info) {
            $this->assertStringStartsWith("/api/library/tracks/{$track->id}/stems/{$stem}?", $info['url']);
            $download = $this->get($info['url']); // no token: the signature is the authorization
            $download->assertOk();
            $this->assertSame("audio of {$stem} as mp3", $download->baseResponse->getFile()->getContent());
            $this->assertSame('audio/mpeg', $download->headers->get('Content-Type'));
        }
    }

    public function test_a_download_answers_range_requests(): void
    {
        [$file, $track] = $this->localTrack();
        $this->runJob($file);
        $url = $this->getJson("/api/library/tracks/{$track->id}/stems", $this->auth())->json('stems.drums.url');

        $this->get($url, ['Range' => 'bytes=0-4'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-4/'.strlen('audio of drums as mp3'));
    }

    public function test_an_unsigned_tampered_or_expired_download_is_refused(): void
    {
        [$file, $track] = $this->localTrack();
        $this->runJob($file);
        $url = $this->getJson("/api/library/tracks/{$track->id}/stems", $this->auth())->json('stems.vocals.url');

        $this->get("/api/library/tracks/{$track->id}/stems/vocals")->assertForbidden();
        $this->get(str_replace('/vocals?', '/bass?', $url))->assertForbidden();

        $this->travel(2)->hours();
        $this->get($url)->assertForbidden();
    }

    public function test_the_list_notices_files_that_are_gone_from_the_disk(): void
    {
        [$file, $track] = $this->localTrack();
        $this->runJob($file);
        Storage::disk('stems')->delete("stems/{$file->id}/bass.mp3");

        $this->getJson("/api/library/tracks/{$track->id}/stems", $this->auth())->assertNotFound()->assertJsonPath('error', 'files_missing');
    }

    // --- remove and prune ------------------------------------------------------------

    public function test_removing_the_kind_deletes_the_files_and_the_record_but_not_the_lanes(): void
    {
        [$file, $track] = $this->localTrack();
        $this->runJob($file);

        $this->deleteJson("/api/library/tracks/{$track->id}/deep-analysis/stem_audio", [], $this->auth())->assertOk()->assertJsonPath('removed', true);

        $analysis = $file->analysis()->first();
        $this->assertNull($analysis->stem_audio);
        $this->assertNotNull($analysis->stems);
        $this->assertSame([], Storage::disk('stems')->allFiles());
        $this->assertSame('none', DeepAnalysis::state($file, $analysis, 'stem_audio')['state']);
    }

    public function test_a_changed_file_forgets_the_manifest_and_prune_removes_what_is_left(): void
    {
        [$file] = $this->localTrack();
        [$other] = $this->localTrack('Other', 'h2');
        $this->runJob($file);
        $this->runJob($other);

        $analysis = $file->analysis()->first();
        $analysis->resetFor('new-hash');
        $analysis->save();
        Storage::disk('stems')->put('stems/9999/vocals.mp3', 'a file that was removed');

        $this->artisan('stems:prune', ['--dry-run' => true])->expectsOutput('Would remove 2 stem audio directories.')->assertSuccessful();
        $this->assertTrue(Storage::disk('stems')->exists("stems/{$file->id}/vocals.mp3"), 'a dry run removes nothing');

        $this->artisan('stems:prune')->expectsOutput('Removed 2 stem audio directories.')->assertSuccessful();
        $this->assertSame([], Storage::disk('stems')->files("stems/{$file->id}"));
        $this->assertSame([], Storage::disk('stems')->files('stems/9999'));
        $this->assertCount(4, Storage::disk('stems')->files("stems/{$other->id}"), 'kept stems stay');
    }
}
