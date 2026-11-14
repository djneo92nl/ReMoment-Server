<?php

namespace Tests\Feature;

use App\Domain\Analysis\AnalysisProgress;
use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\Stages\StemsStage;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Health\Queues;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Jobs\AnalyzeAudioFile;
use App\Jobs\AnalyzeTrackDeep;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools as McpTools;
use App\Models\AdminToken;
use App\Models\Device;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Stems of one track on request (stems stage, AnalyzeTrackDeep on the `stems` queue, kind `stems` of deep-analysis). */
class StemsDeepAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = [
        'version' => 1, 'duration' => 3.0, 'method' => 'htdemucs', 'rate_hz' => 2,
        'stems' => [
            'vocals' => [-80, -30, -29.6, -31, -80, -80],
            'drums' => [-20, -21, -20, -22, -20, -19],
            'bass' => [-25, -25, -26, -25, -25, -24],
            'other' => [-40, -41, -39, -42, -40, 3],
        ],
        'vocal_ratio' => 0.5, 'instrumental' => [['start' => 2.0, 'end' => 3.0]],
    ];

    private string $dir;

    private string $token;

    /** @var array{0: array, 1: int}|\Closure the answer of the fake stems service */
    private $stemsAnswer;

    private bool $serviceHealthy = true;

    /** @var \Closure|null overrides the answer of the fake service's /health */
    private ?\Closure $healthAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        $this->stemsAnswer = [self::SERVICE, 200];
        Http::fake([
            'stems:8092/health' => fn () => $this->healthAnswer
                ? ($this->healthAnswer)()
                : ($this->serviceHealthy ? Http::response(['ok' => true, 'version' => 1, 'method' => 'htdemucs']) : Http::response('', 503)),
            'stems:8092/stems' => fn () => $this->stemsAnswer instanceof \Closure ? ($this->stemsAnswer)() : Http::response(...$this->stemsAnswer),
        ]);
        // The runner has no ffmpeg; the stage only asks `ffmpeg -version`, so answer that.
        \App\Domain\Analysis\Tools::forget();
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result("ffmpeg version 7\n")]);
        config(['analysis.enabled' => true, 'analysis.stems_url' => 'http://stems:8092', 'analysis.structure_url' => null, 'analysis.essentia_url' => null]);

        $this->dir = sys_get_temp_dir().'/remoment-stems-'.uniqid();
        mkdir($this->dir);

        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
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

    private function runJob(File $file): void
    {
        (new AnalyzeTrackDeep($file, 'stems'))->handle();
    }

    private function url(Track $track): string
    {
        return "/api/library/tracks/{$track->id}/deep-analysis";
    }

    // --- the stage ---------------------------------------------------------------

    public function test_the_stage_stores_the_lanes_as_clamped_integers_with_version_and_time(): void
    {
        [$file] = $this->localTrack();

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $stems = $analysis->stems;
        $this->assertSame('htdemucs', $stems['method']);
        $this->assertSame(2, $stems['rate_hz']);
        $this->assertSame(1, $stems['service_version']);
        $this->assertSame([-80, -30, -30, -31, -80, -80], $stems['lanes']['vocals'], 'dB rounded to integers');
        $this->assertSame(0, $stems['lanes']['other'][5], 'clamped to 0 dB');
        $this->assertSame(['vocals', 'drums', 'bass', 'other'], array_keys($stems['lanes']));
        $this->assertSame(0.5, $stems['vocal_ratio']);
        $this->assertEquals([['start' => 2.0, 'end' => 3.0]], $stems['instrumental']);
        $this->assertSame('1', $analysis->stageVersion('stems'));
        $this->assertNotNull($analysis->stages['stems']['at']);
        $this->assertSame('done', DeepAnalysis::state($file, $analysis, 'stems')['state']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/stems') && $request->isMultipart());
    }

    public function test_an_answer_without_a_lane_or_with_uneven_lanes_is_a_failure(): void
    {
        $broken = self::SERVICE;
        unset($broken['stems']['bass']);
        $this->stemsAnswer = [$broken, 200];
        [$file] = $this->localTrack();

        $this->runJob($file);

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'stems');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('bass lane', $state['message']);
        $this->assertNull($file->analysis()->first()->stems);

        $uneven = self::SERVICE;
        $uneven['stems']['drums'] = [-20, -21];
        $this->stemsAnswer = [$uneven, 200];
        $this->runJob($file);
        $this->assertStringContainsString('different lengths', DeepAnalysis::state($file, $file->analysis()->first(), 'stems')['message']);
    }

    public function test_a_413_is_a_failure_with_a_clear_message_and_no_stuck_marker(): void
    {
        $this->stemsAnswer = [['detail' => 'The track is 25.0 minutes; this service takes at most 20 minutes (STEMS_MAX_DURATION).'], 413];
        [$file] = $this->localTrack();
        Cache::put(DeepAnalysis::marker('queued', $file, 'stems'), true, 600);

        $this->runJob($file);

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'stems');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('Too long for the stems service', $state['message']);
        $this->assertStringContainsString('20 minutes', $state['message']);
        $this->assertFalse(Cache::has(DeepAnalysis::marker('running', $file, 'stems')));
        $this->assertFalse(Cache::has(DeepAnalysis::marker('queued', $file, 'stems')));
    }

    public function test_the_model_not_being_available_is_a_retriable_failure(): void
    {
        $this->stemsAnswer = [['detail' => 'The model is not available: offline'], 503];
        [$file] = $this->localTrack();

        $this->runJob($file);

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'stems');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('HTTP 503', $state['message']);
        $this->assertStringContainsString('offline', $state['message']);

        $this->stemsAnswer = [self::SERVICE, 200];
        $this->runJob($file);
        $this->assertSame('done', DeepAnalysis::state($file, $file->analysis()->first(), 'stems')['state']);
    }

    public function test_the_service_being_down_or_too_slow_is_a_failure_that_clears_the_running_marker(): void
    {
        $this->stemsAnswer = fn () => throw new \Illuminate\Http\Client\ConnectionException('refused');
        [$file] = $this->localTrack();

        $this->runJob($file);

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'stems');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('not reachable', $state['message']);
        $this->assertFalse(Cache::has(DeepAnalysis::marker('running', $file, 'stems')));
    }

    public function test_the_failed_hook_clears_markers_and_records_the_error(): void
    {
        [$file] = $this->localTrack();
        Cache::put(DeepAnalysis::marker('running', $file, 'stems'), true, 600);

        (new AnalyzeTrackDeep($file, 'stems'))->failed(new \RuntimeException('timed out'));

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'stems');
        $this->assertSame('failed', $state['state']);
        $this->assertSame('timed out', $state['message']);
        $this->assertFalse(Cache::has(DeepAnalysis::marker('running', $file, 'stems')));
    }

    public function test_the_job_runs_on_the_stems_queue_with_a_timeout_below_its_worker_and_retry_after(): void
    {
        [$file] = $this->localTrack();
        $job = new AnalyzeTrackDeep($file, 'stems');

        $this->assertSame('stems', $job->queue);
        $this->assertSame(3060, $job->timeout);
        $this->assertSame('analysis', (new AnalyzeTrackDeep($file, 'sections'))->queue);

        $conf = file_get_contents(base_path('docker/8.4/supervisord.prod.conf'));
        preg_match('/\[program:stems-queue\](.*?)(?=\n\[program:)/s', $conf, $m);
        $this->assertMatchesRegularExpression('/--queue=stems /', $m[1]);
        preg_match('/--timeout=(\d+)/', $m[1], $t);
        preg_match('/stopwaitsecs=(\d+)/', $m[1], $w);
        $this->assertGreaterThan($job->timeout, (int) $t[1]);
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), (int) $t[1]);
        $this->assertGreaterThan((int) $t[1], (int) $w[1], 'supervisor waits for the longest job before killing the worker');
        $this->assertStringContainsString('--memory=', $m[1]);
        $this->assertStringContainsString('nice -n 15', $m[1]);
        $this->assertSame(1, preg_match_all('/--queue=stems/', $conf), 'exactly one stems worker');
    }

    public function test_the_stems_queue_is_known_to_the_health_page_and_the_alert_model(): void
    {
        $this->assertContains('stems', Queues::all());
        $this->assertContains('stems', Queues::heartbeat());
        $this->assertSame('health:queue:stems', Queues::heartbeatKey('stems'));
        $this->assertGreaterThan(3060, Queues::maxRuntime('stems'));
        $this->assertSame(1000, Queues::maxRuntime('analysis'));
    }

    // --- nothing in the nightly run ------------------------------------------------

    public function test_the_nightly_backlog_and_the_progress_totals_ignore_stems(): void
    {
        Queue::fake();
        [$file] = $this->localTrack();
        \App\Models\FileAnalysis::create([
            'file_id' => $file->id, 'content_hash' => 'h1',
            'stages' => array_map(fn ($stage) => ['version' => $stage->version(), 'at' => now()->toIso8601String()], AudioAnalysis::stages()),
            'errors' => [],
        ]);

        $this->assertArrayNotHasKey('stems', AudioAnalysis::stages());
        $this->assertArrayHasKey('stems', AudioAnalysis::onDemandStages());
        $this->assertSame(0, AudioAnalysis::pending()->count());
        $this->assertSame(0, AudioAnalysis::queueBacklog(10));
        Queue::assertNotPushed(AnalyzeAudioFile::class);
        Queue::assertNotPushed(AnalyzeTrackDeep::class);

        $progress = AnalysisProgress::compute();
        $this->assertArrayNotHasKey('stems', $progress['stages']);
        $this->assertSame(1, $progress['complete']);
        $this->assertArrayNotHasKey('stems', AnalysisProgress::forFile($file->analysis()->first(), 'h1'));
        $this->assertArrayNotHasKey('stems', AudioAnalysis::pendingStages($file, null, AudioAnalysis::enabledStages()));
    }

    public function test_the_analysis_job_never_runs_the_stems_stage(): void
    {
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file, 'stems');

        $this->assertNull($file->analysis()->first()?->stems);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/stems'));
    }

    // --- the endpoints ---------------------------------------------------------------

    public function test_the_endpoints_need_the_admin_token(): void
    {
        [, $track] = $this->localTrack();

        $this->postJson($this->url($track), ['kinds' => ['stems']])->assertUnauthorized();
        $this->getJson($this->url($track))->assertUnauthorized();
        $this->deleteJson($this->url($track).'/stems')->assertUnauthorized();
    }

    public function test_requesting_stems_queues_a_job_on_the_stems_queue_with_an_estimate_and_ram_note(): void
    {
        Queue::fake();
        [$file, $track] = $this->localTrack();

        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())
            ->assertStatus(202)
            ->assertExactJson(['status' => 'queued', 'queued' => ['stems'], 'estimate' => ['sections_seconds' => 5, 'stems_seconds' => 105, 'stems_ram_mb' => 1500]]);

        Queue::assertPushedOn('stems', AnalyzeTrackDeep::class, fn ($job) => $job->file->is($file) && $job->kind === 'stems');
        $this->getJson($this->url($track), $this->auth())
            ->assertOk()
            ->assertJsonPath('kinds.stems.state', 'queued')
            ->assertJsonPath('kinds.stems.available', true)
            ->assertJsonPath('kinds.sections.available', false)
            ->assertJsonPath('available', true);

        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())->assertStatus(202)->assertJsonPath('queued', ['stems']);
        Queue::assertPushed(AnalyzeTrackDeep::class, 1);
    }

    public function test_a_stored_result_is_already_done_and_can_be_removed_and_asked_again(): void
    {
        Queue::fake();
        [$file, $track] = $this->localTrack();
        $this->runJob($file);

        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())->assertOk()->assertJsonPath('status', 'already_done');
        Queue::assertNothingPushed();
        $this->assertSame('done', $this->getJson($this->url($track), $this->auth())->json('kinds.stems.state'));

        $this->deleteJson($this->url($track).'/stems', [], $this->auth())->assertOk()->assertExactJson(['status' => 'ok', 'removed' => true]);
        $this->assertNull($file->analysis()->first()->stems);
        $this->assertSame('none', $this->getJson($this->url($track), $this->auth())->json('kinds.stems.state'));
        $this->deleteJson($this->url($track).'/stems', [], $this->auth())->assertJsonPath('removed', false);

        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())->assertStatus(202);
    }

    public function test_not_available_without_a_url_or_a_healthy_service_and_nothing_is_queued_for_the_other_kind_either(): void
    {
        Queue::fake();
        config(['analysis.structure_url' => 'http://structure:8091']);
        Http::fake(['structure:8091/health' => Http::response(['ok' => true])]);
        [, $track] = $this->localTrack();

        config(['analysis.stems_url' => null]);
        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())->assertStatus(409)->assertJsonPath('status', 'not_available');
        $this->postJson($this->url($track), ['kinds' => ['sections', 'stems']], $this->auth())->assertStatus(409)->assertJsonPath('queued', []);

        config(['analysis.stems_url' => 'http://stems:8092']);
        Cache::flush();
        $this->serviceHealthy = false;
        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_the_model_still_loading_counts_as_not_available(): void
    {
        Queue::fake();
        $this->healthAnswer = fn () => Http::response(['ok' => false, 'loading' => true, 'error' => null]);
        [, $track] = $this->localTrack();

        $this->postJson($this->url($track), ['kinds' => ['stems']], $this->auth())->assertStatus(409);
    }

    public function test_both_kinds_can_be_asked_for_together_each_on_its_own_queue(): void
    {
        Queue::fake();
        config(['analysis.structure_url' => 'http://structure:8091']);
        Http::fake(['structure:8091/health' => Http::response(['ok' => true])]);
        [, $track] = $this->localTrack();

        $this->postJson($this->url($track), ['kinds' => ['sections', 'stems']], $this->auth())->assertStatus(202)->assertJsonPath('queued', ['sections', 'stems']);

        Queue::assertPushedOn('analysis', AnalyzeTrackDeep::class, fn ($job) => $job->kind === 'sections');
        Queue::assertPushedOn('stems', AnalyzeTrackDeep::class, fn ($job) => $job->kind === 'stems');
    }

    public function test_a_merged_track_id_resolves_to_the_survivor(): void
    {
        Queue::fake();
        [$file, $track] = $this->localTrack();
        DB::table('id_aliases')->insert(['entity' => 'track', 'old_id' => 9001, 'new_id' => $track->id]);

        $this->getJson('/api/library/tracks/9001/deep-analysis', $this->auth())->assertOk()->assertJsonPath('track_id', $track->id);
        $this->postJson('/api/library/tracks/9001/deep-analysis', ['kinds' => ['stems']], $this->auth())->assertStatus(202);
        Queue::assertPushed(AnalyzeTrackDeep::class, fn ($job) => $job->file->is($file) && $job->kind === 'stems');
    }

    // --- where the stems show ----------------------------------------------------------

    public function test_stems_are_in_the_track_details_and_the_album_api(): void
    {
        [$file, $track] = $this->localTrack();

        $details = $track->details();
        $this->assertNull($details['stems']);
        $this->assertNull($details['vocal_ratio']);
        $this->assertNull($details['instrumental']);

        $this->runJob($file);

        $details = $track->fresh()->details();
        $this->assertSame(['method', 'rate_hz', 'vocals', 'drums', 'bass', 'other'], array_keys($details['stems']));
        $this->assertSame('htdemucs', $details['stems']['method']);
        $this->assertSame(2, $details['stems']['rate_hz']);
        $this->assertSame([-80, -30, -30, -31, -80, -80], $details['stems']['vocals']);
        $this->assertSame(0.5, $details['vocal_ratio']);
        $this->assertEquals([['start' => 2.0, 'end' => 3.0]], $details['instrumental']);

        $api = $this->getJson("/api/library/albums/{$track->album_id}")->assertOk()->json('tracks.0.details');
        $this->assertSame(0.5, $api['vocal_ratio']);
        $this->assertCount(6, $api['stems']['bass']);
        $this->assertNull($api['sections'], 'sections were not asked for');
    }

    public function test_stems_are_in_now_playing_of_the_device_for_the_analysed_track(): void
    {
        [$file, $track] = $this->localTrack();
        $this->runJob($file);
        $device = Device::factory()->create();
        DeviceCache::updateState($device->id, State::Playing);
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(
            track: new TrackData(name: 'Song', artist: new ArtistData(name: 'Band'), duration: 200),
        ));

        $response = $this->getJson("/api/devices/{$device->id}")->assertOk();

        $response->assertJsonPath('data.now_playing.stems.method', 'htdemucs')
            ->assertJsonPath('data.now_playing.stems.rate_hz', 2)
            ->assertJsonPath('data.now_playing.vocal_ratio', 0.5)
            ->assertJsonPath('data.now_playing.instrumental', [['start' => 2, 'end' => 3]]);
        $this->assertCount(6, $response->json('data.now_playing.stems.vocals'));

        // A track nobody asked stems for carries none of the keys.
        Cache::flush();
        $other = Device::factory()->create();
        DeviceCache::updateState($other->id, State::Playing);
        DeviceCache::updateNowPlaying($other->id, new NowPlaying(
            track: new TrackData(name: 'Elsewhere', artist: new ArtistData(name: 'Someone'), duration: 100),
        ));
        $this->assertArrayNotHasKey('stems', $this->getJson("/api/devices/{$other->id}")->json('data.now_playing'));
    }

    // --- /api/info and the health page -------------------------------------------------

    public function test_features_list_the_kinds_that_can_run(): void
    {
        $this->getJson('/api/info')->assertJsonPath('features', ['alerts' => true, 'profile' => 'full', 'deep_analysis' => true, 'analysis' => true, 'deep_analysis_kinds' => ['stems']] + $this->exportFeature());

        config(['analysis.structure_url' => 'http://structure:8091']);
        Http::fake(['structure:8091/health' => Http::response(['ok' => true])]);
        Cache::flush();
        $this->getJson('/api/info')->assertJsonPath('features.deep_analysis_kinds', ['sections', 'stems']);

        config(['analysis.stems_url' => null, 'analysis.structure_url' => null]);
        Cache::flush();
        $this->getJson('/api/info')->assertJsonPath('features', ['alerts' => true, 'profile' => 'full', 'deep_analysis' => false, 'analysis' => true] + $this->exportFeature());
    }

    public function test_the_stems_probe_is_disabled_without_a_url_and_says_what_is_wrong_with_one(): void
    {
        Cache::flush();
        config(['analysis.stems_url' => null]);
        $this->assertSame('disabled', (new \App\Domain\Health\ExternalServices)->refresh(['stems'])['stems']['status']);

        config(['analysis.stems_url' => 'http://stems:8092']);
        $answers = [
            Http::response(['ok' => true, 'version' => 1, 'method' => 'htdemucs']),
            Http::response(['ok' => false, 'loading' => true, 'error' => null]),
            Http::response(['ok' => false, 'loading' => false, 'error' => 'offline']),
            Http::response('', 503),
        ];
        $this->healthAnswer = function () use (&$answers) {
            return array_shift($answers);
        };

        $this->assertSame('ok', (new \App\Domain\Health\ExternalServices)->refresh(['stems'])['stems']['status']);
        $this->assertSame('warning', (new \App\Domain\Health\ExternalServices)->refresh(['stems'])['stems']['status']);
        $this->assertSame('error', (new \App\Domain\Health\ExternalServices)->refresh(['stems'])['stems']['status']);
        $this->assertSame('error', (new \App\Domain\Health\ExternalServices)->refresh(['stems'])['stems']['status']);
    }

    // --- MCP ---------------------------------------------------------------------------

    public function test_the_mcp_tool_requests_and_removes_stems(): void
    {
        Queue::fake();
        [, $track] = $this->localTrack();
        request()->headers->set('Authorization', 'Bearer '.$this->token);

        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'request', 'track_id' => $track->id, 'kinds' => ['stems']])
            ->assertOk()->assertSee('queued')->assertSee('stems_seconds');
        Queue::assertPushedOn('stems', AnalyzeTrackDeep::class);
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'status', 'track_id' => $track->id])
            ->assertOk()->assertSee('stems');
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'remove', 'track_id' => $track->id, 'kind' => 'stems'])->assertOk();
    }

    public function test_the_attributes_clamp_and_ignore_malformed_ranges(): void
    {
        $out = StemsStage::attributes([
            'stems' => ['vocals' => [-100, 5], 'drums' => [-1.4, -1.6], 'bass' => [0, 0], 'other' => [-80, -79]],
            'instrumental' => [['start' => 1, 'end' => 2], ['start' => 'x'], 'junk'],
            'vocal_ratio' => 7,
        ]);

        $this->assertSame([-80, 0], $out['lanes']['vocals']);
        $this->assertSame([-1, -2], $out['lanes']['drums']);
        $this->assertEquals([['start' => 1.0, 'end' => 2.0]], $out['instrumental']);
        $this->assertSame(1.0, $out['vocal_ratio']);
        $this->assertSame(2, $out['rate_hz']);
    }

    /** `features.export` is there when this machine has ffmpeg (ExportApiTest covers it). */
    private function exportFeature(): array
    {
        return \App\Domain\Analysis\Tools::available(\App\Domain\Analysis\Tools::ffmpeg()) ? ['export' => true] : [];
    }
}
