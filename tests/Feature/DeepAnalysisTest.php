<?php

namespace Tests\Feature;

use App\Domain\Analysis\AnalysisProgress;
use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\Stages\StructureStage;
use App\Domain\Analysis\Tools;
use App\Domain\Library\IdAliases;
use App\Jobs\AnalyzeAudioFile;
use App\Jobs\AnalyzeTrackDeep;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools as McpTools;
use App\Models\AdminToken;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sections of one track on request (structure stage, AnalyzeTrackDeep, /api/library/tracks/{id}/deep-analysis). */
class DeepAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = [
        'version' => 1, 'duration' => 200.0, 'tempo' => 103.4, 'method' => 'novelty-v1',
        'sections' => [
            ['start' => 0.0, 'end' => 23.1, 'label' => 'A', 'confidence' => 0.71],
            ['start' => 23.1, 'end' => 200.0, 'label' => null, 'confidence' => 0.0],
        ],
    ];

    private string $dir;

    private string $token;

    /** @var array{0: array, 1: int}|\Closure the answer of the fake structure service */
    private $structureAnswer;

    private bool $serviceHealthy = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tools::forget();
        Cache::flush();
        Http::preventStrayRequests();
        $this->structureAnswer = [self::SERVICE, 200];
        Http::fake([
            'structure:8091/health' => fn () => $this->serviceHealthy ? Http::response(['ok' => true, 'version' => 1, 'method' => 'novelty-v1']) : Http::response('', 503),
            'structure:8091/structure' => fn () => $this->structureAnswer instanceof \Closure ? ($this->structureAnswer)() : Http::response(...$this->structureAnswer),
        ]);
        config(['analysis.enabled' => true, 'analysis.structure_url' => 'http://structure:8091', 'analysis.essentia_url' => null]);

        $this->dir = sys_get_temp_dir().'/remoment-deep-'.uniqid();
        mkdir($this->dir);

        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            $line = implode(' ', $command);

            if (str_contains($line, '-version')) {
                return Process::result("ffmpeg version 7\n");
            }
            if (str_contains($line, '-filter_complex')) {
                foreach ($command as $i => $argument) {
                    if ($argument === '-f' && ($command[$i + 1] ?? null) === 'wav') {
                        file_put_contents($command[$i + 2], 'RIFF');
                    }
                }

                return Process::result();
            }

            return Process::result('', "unexpected: {$line}", 1);
        });

        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        Tools::forget();

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

    private function serviceUp(array $sectionsResponse = self::SERVICE, int $status = 200): void
    {
        $this->serviceHealthy = true;
        $this->structureAnswer = [$sectionsResponse, $status];
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function runJob(File $file): void
    {
        (new AnalyzeTrackDeep($file, 'sections'))->handle();
    }

    // --- the stage -------------------------------------------------------------

    public function test_the_stage_stores_the_sections_with_method_version_and_time(): void
    {
        $this->serviceUp();
        [$file] = $this->localTrack();

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $this->assertSame('novelty-v1', $analysis->sections['method']);
        $this->assertEquals(103.4, $analysis->sections['tempo']);
        $this->assertNull($analysis->sections['reason']);
        $this->assertEquals(['start' => 0.0, 'end' => 23.1, 'label' => 'A', 'confidence' => 0.71], $analysis->sections['items'][0]);
        $this->assertNull($analysis->sections['items'][1]['label']);
        $this->assertSame('1', $analysis->stageVersion('structure'));
        $this->assertNotNull($analysis->stages['structure']['at']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/structure') && $request->isMultipart());
    }

    public function test_no_sections_are_stored_as_empty_with_the_reason(): void
    {
        $this->serviceUp(['version' => 1, 'duration' => 20.0, 'tempo' => null, 'method' => 'novelty-v1', 'sections' => [], 'reason' => 'too_short']);
        [$file, $track] = $this->localTrack();

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $this->assertSame([], $analysis->sections['items']);
        $this->assertSame('too_short', $analysis->sections['reason']);
        $this->assertSame('1', $analysis->stageVersion('structure'), 'a finished run, not a failure');
        $this->assertSame(['sections' => [], 'reason' => 'too_short', 'method' => 'novelty-v1'], $track->fresh()->sections());
        $this->assertSame('done', DeepAnalysis::state($file, $analysis, 'sections')['state']);
    }

    public function test_a_413_is_a_failure_with_a_clear_message_and_no_stuck_marker(): void
    {
        $this->serviceUp(['detail' => 'The track is 25.0 minutes; this service takes at most 20 minutes (STRUCTURE_MAX_DURATION).'], 413);
        [$file] = $this->localTrack();
        Cache::put(DeepAnalysis::marker('queued', $file, 'sections'), true, 600);

        $this->runJob($file);

        $analysis = $file->analysis()->first();
        $state = DeepAnalysis::state($file, $analysis, 'sections');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('Too long for the structure service', $state['message']);
        $this->assertStringContainsString('20 minutes', $state['message']);
        $this->assertNull($analysis->sections);
        $this->assertFalse(Cache::has(DeepAnalysis::marker('running', $file, 'sections')));
        $this->assertFalse(Cache::has(DeepAnalysis::marker('queued', $file, 'sections')));
    }

    public function test_the_service_being_down_is_a_retriable_failure(): void
    {
        $this->structureAnswer = fn () => throw new \Illuminate\Http\Client\ConnectionException('refused');
        [$file] = $this->localTrack();

        $this->runJob($file);

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'sections');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('not reachable', $state['message']);
        $this->assertFalse(Cache::has(DeepAnalysis::marker('running', $file, 'sections')));

        // Asking again (service back) runs it and clears the failure.
        $this->serviceUp();
        $this->runJob($file);
        $this->assertSame('done', DeepAnalysis::state($file, $file->analysis()->first(), 'sections')['state']);
    }

    public function test_the_failed_hook_clears_markers_and_records_the_error(): void
    {
        [$file] = $this->localTrack();
        $job = new AnalyzeTrackDeep($file, 'sections');
        Cache::put(DeepAnalysis::marker('running', $file, 'sections'), true, 600);

        $job->failed(new \RuntimeException('timed out'));

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'sections');
        $this->assertSame('failed', $state['state']);
        $this->assertSame('timed out', $state['message']);
    }

    public function test_a_changed_file_makes_stored_sections_stale(): void
    {
        $this->serviceUp();
        [$file] = $this->localTrack();
        $this->runJob($file);

        $file->update(['hash' => 'h2']);

        $this->assertSame('none', DeepAnalysis::state($file->fresh(), $file->analysis()->first(), 'sections')['state']);
    }

    // --- nothing in the nightly run --------------------------------------------

    public function test_the_nightly_backlog_and_the_progress_totals_ignore_sections(): void
    {
        Queue::fake();
        [$file] = $this->localTrack();
        // Everything scheduled is done; sections were never asked for.
        FileAnalysis::create([
            'file_id' => $file->id, 'content_hash' => 'h1',
            'stages' => array_map(fn ($stage) => ['version' => $stage->version(), 'at' => now()->toIso8601String()], AudioAnalysis::stages()),
            'errors' => [],
        ]);

        $this->assertArrayNotHasKey('structure', AudioAnalysis::stages());
        $this->assertArrayHasKey('structure', AudioAnalysis::onDemandStages());
        $this->assertSame(0, AudioAnalysis::pending()->count());
        $this->assertSame(0, AudioAnalysis::queueBacklog(10));
        Queue::assertNotPushed(AnalyzeAudioFile::class);
        Queue::assertNotPushed(AnalyzeTrackDeep::class);

        $progress = AnalysisProgress::compute();
        $this->assertArrayNotHasKey('structure', $progress['stages']);
        $this->assertSame(1, $progress['complete']);
        $this->assertSame(0, $progress['backlog']);
        $this->assertArrayNotHasKey('structure', AnalysisProgress::forFile($file->analysis()->first(), 'h1'));

        // A file that was never analysed needs the scheduled stages only, never `structure`.
        $this->assertSame(array_keys(AudioAnalysis::enabledStages()), array_keys(AudioAnalysis::pendingStages($file, null, AudioAnalysis::enabledStages())));
    }

    public function test_the_analysis_job_never_runs_the_structure_stage(): void
    {
        $this->serviceUp();
        [$file] = $this->localTrack();

        AnalyzeAudioFile::dispatchSync($file, 'structure');

        $this->assertNull($file->analysis()->first()?->sections);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/structure'));
    }

    // --- the endpoints -----------------------------------------------------------

    public function test_the_endpoints_need_the_admin_token(): void
    {
        [, $track] = $this->localTrack();

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']])->assertUnauthorized();
        $this->getJson("/api/library/tracks/{$track->id}/deep-analysis")->assertUnauthorized();
        $this->deleteJson("/api/library/tracks/{$track->id}/deep-analysis/sections")->assertUnauthorized();
    }

    public function test_requesting_sections_queues_the_job_and_gives_an_estimate(): void
    {
        Queue::fake();
        $this->serviceUp();
        [$file, $track] = $this->localTrack();

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']], $this->auth())
            ->assertStatus(202)
            ->assertExactJson(['status' => 'queued', 'queued' => ['sections'], 'estimate' => ['sections_seconds' => 5]]);

        Queue::assertPushedOn('analysis', AnalyzeTrackDeep::class, fn ($job) => $job->file->is($file) && $job->kind === 'sections');
        $this->getJson("/api/library/tracks/{$track->id}/deep-analysis", $this->auth())
            ->assertOk()
            ->assertJsonPath('kinds.sections.state', 'queued')
            ->assertJsonPath('available', true);

        // Asking again while queued does not queue a second job.
        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']], $this->auth())
            ->assertStatus(202)->assertJsonPath('queued', ['sections']);
        Queue::assertPushed(AnalyzeTrackDeep::class, 1);
    }

    public function test_a_stored_result_is_already_done_and_can_be_removed(): void
    {
        Queue::fake();
        $this->serviceUp();
        [$file, $track] = $this->localTrack();
        $this->runJob($file);

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']], $this->auth())
            ->assertOk()->assertJsonPath('status', 'already_done')->assertJsonPath('queued', []);
        Queue::assertNothingPushed();

        $state = $this->getJson("/api/library/tracks/{$track->id}/deep-analysis", $this->auth())->assertOk()->json('kinds.sections');
        $this->assertSame('done', $state['state']);
        $this->assertSame('1', $state['version']);
        $this->assertNotNull($state['finished_at']);

        $this->deleteJson("/api/library/tracks/{$track->id}/deep-analysis/sections", [], $this->auth())
            ->assertOk()->assertExactJson(['status' => 'ok', 'removed' => true]);
        $this->assertNull($file->analysis()->first()->sections);
        $this->assertSame('none', $this->getJson("/api/library/tracks/{$track->id}/deep-analysis", $this->auth())->json('kinds.sections.state'));
        $this->deleteJson("/api/library/tracks/{$track->id}/deep-analysis/sections", [], $this->auth())->assertJsonPath('removed', false);

        // Once removed it can be requested again.
        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']], $this->auth())->assertStatus(202);
    }

    public function test_not_available_without_a_url_or_a_healthy_service(): void
    {
        Queue::fake();
        [, $track] = $this->localTrack();

        config(['analysis.structure_url' => null]);
        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']], $this->auth())
            ->assertStatus(409)->assertJsonPath('status', 'not_available')->assertJsonPath('queued', []);

        config(['analysis.structure_url' => 'http://structure:8091']);
        Cache::flush();
        $this->serviceHealthy = false;
        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['sections']], $this->auth())
            ->assertStatus(409)->assertJsonPath('status', 'not_available');

        Queue::assertNothingPushed();
    }

    public function test_unknown_kinds_a_missing_list_and_a_track_without_a_file_are_422(): void
    {
        $this->serviceUp();
        [, $track] = $this->localTrack();
        $url = "/api/library/tracks/{$track->id}/deep-analysis";

        $this->postJson($url, ['kinds' => ['stems']], $this->auth())->assertStatus(422)->assertJsonPath('error', 'unknown_kind');
        $this->postJson($url, ['kinds' => ['sections', 'mood']], $this->auth())->assertStatus(422)->assertJsonPath('error', 'unknown_kind');
        $this->postJson($url, [], $this->auth())->assertStatus(422);
        $this->deleteJson("{$url}/stems", [], $this->auth())->assertStatus(422)->assertJsonPath('error', 'unknown_kind');

        $streaming = Track::create(['artist_id' => $track->artist_id, 'album_id' => $track->album_id, 'name' => 'Stream', 'duration' => 100, 'source' => 'spotify', 'external_id' => 'spotify:track:x']);
        foreach ([fn () => $this->postJson("/api/library/tracks/{$streaming->id}/deep-analysis", ['kinds' => ['sections']], $this->auth()), fn () => $this->getJson("/api/library/tracks/{$streaming->id}/deep-analysis", $this->auth())] as $call) {
            $call()->assertStatus(422)->assertJsonPath('error', 'no_file');
        }

        $this->getJson('/api/library/tracks/99999/deep-analysis', $this->auth())->assertNotFound();
    }

    public function test_a_merged_track_id_resolves_to_the_survivor(): void
    {
        Queue::fake();
        $this->serviceUp();
        [$file, $track] = $this->localTrack();
        DB::table('id_aliases')->insert(['entity' => 'track', 'old_id' => 9001, 'new_id' => $track->id]);
        $this->assertSame($track->id, app(IdAliases::class)->resolve('track', 9001));

        $this->getJson('/api/library/tracks/9001/deep-analysis', $this->auth())->assertOk()->assertJsonPath('track_id', $track->id);
        $this->postJson('/api/library/tracks/9001/deep-analysis', ['kinds' => ['sections']], $this->auth())->assertStatus(202);
        Queue::assertPushed(AnalyzeTrackDeep::class, fn ($job) => $job->file->is($file));
    }

    // --- where the sections show -------------------------------------------------

    public function test_sections_are_in_the_track_details(): void
    {
        $this->serviceUp();
        [$file, $track] = $this->localTrack();

        $this->assertNull($track->details()['sections']);

        $this->runJob($file);

        $details = $track->fresh()->details();
        $this->assertCount(2, $details['sections']);
        $this->assertEquals(0.0, $details['sections'][0]['start']);
        $this->assertSame('A', $details['sections'][0]['label']);
        $this->assertNull($details['sections_reason']);

        $album = $this->getJson("/api/library/albums/{$track->album_id}")->assertOk()->json('tracks.0.details');
        $this->assertCount(2, $album['sections']);
        $this->assertArrayHasKey('waveform', ['waveform' => null] + $album, 'waveform fields are untouched');
    }

    // --- /api/info ---------------------------------------------------------------

    public function test_the_feature_flag_needs_a_url_and_a_healthy_service(): void
    {
        config(['analysis.structure_url' => null]);
        $this->assertFalse(DeepAnalysis::anyAvailable());

        config(['analysis.structure_url' => 'http://structure:8091']);
        $this->serviceUp();
        $this->assertTrue(DeepAnalysis::anyAvailable());
        $this->assertTrue((new StructureStage)->enabled());
    }

    // --- MCP ---------------------------------------------------------------------

    public function test_the_mcp_tool_is_admin_only_and_goes_through_the_api(): void
    {
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'status', 'track_id' => 1])->assertNotRegistered();

        Queue::fake();
        $this->serviceUp();
        [, $track] = $this->localTrack();
        request()->headers->set('Authorization', 'Bearer '.$this->token);

        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'request', 'track_id' => $track->id])
            ->assertOk()->assertSee('queued')->assertSee('sections_seconds');
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'status', 'track_id' => $track->id])
            ->assertOk()->assertSee('queued');
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'request', 'track_id' => $track->id, 'kinds' => ['stems']])
            ->assertHasErrors(['unknown_kind']);
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'remove', 'track_id' => $track->id])->assertOk();
        Queue::assertPushed(AnalyzeTrackDeep::class, 1);
    }
}
