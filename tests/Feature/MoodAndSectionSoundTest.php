<?php

namespace Tests\Feature;

use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\MoodCurve;
use App\Domain\Analysis\Tools;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\SimilarSections;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Domain\Similar\Embeddings;
use App\Domain\Similar\SectionEmbeddings;
use App\Jobs\AnalyzeTrackDeep;
use App\Livewire\SectionSimilar;
use App\Livewire\TrackMood;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools as McpTools;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\SectionEmbedding;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Mood over time (16.3, kind `mood`) and the sound of the sections (16.5, kind `section_sound`, `similar-sections`), against a fake sidecar. */
class MoodAndSectionSoundTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = ['method' => 'novelty-v1', 'reason' => null, 'items' => [
        ['start' => 0.0, 'end' => 60.0, 'label' => 'A', 'confidence' => 0.8],
        ['start' => 60.0, 'end' => 140.0, 'label' => 'B', 'confidence' => 0.7],
        ['start' => 140.0, 'end' => 200.0, 'label' => 'A', 'confidence' => 0.8],
    ]];

    private string $dir;

    private string $token;

    /** @var array<string, mixed> the fake sidecar's /health */
    private array $health = ['ok' => true, 'ml' => true, 'models' => ['effnet', 'musicnn', 'deam']];

    /** @var array{0: array, 1: int} */
    private array $curveAnswer;

    /** @var array<string, mixed>|null overrides the answer of the fake /section-embeddings */
    private ?array $sectionAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tools::forget();
        Cache::flush();
        Http::preventStrayRequests();
        $this->curveAnswer = [['version' => 1, 'method' => 'deam-msd-musicnn-2', 'duration' => 200.0, 'window' => 3.0,
            'arousal' => [0.1, 0.5, 0.9, 1.4, -0.2], 'valence' => [0.3, 0.3, 0.6, 0.6, 0.5]], 200];
        Http::fake([
            'essentia:8000/health' => fn () => Http::response($this->health),
            'essentia:8000/curve' => fn () => Http::response(...$this->curveAnswer),
            'essentia:8000/section-embeddings' => function ($request) {
                if ($this->sectionAnswer !== null) {
                    return Http::response($this->sectionAnswer);
                }
                $ranges = json_decode(collect($request->data())->firstWhere('name', 'sections')['contents'], true);

                return Http::response(['version' => 1, 'model' => Embeddings::MODEL, 'duration' => 200.0,
                    'sections' => array_map(fn ($r, $i) => ['start' => $r[0], 'end' => $r[1], 'embedding' => $this->vector($i)], $ranges, array_keys($ranges))]);
            },
        ]);
        config(['analysis.enabled' => true, 'analysis.essentia_url' => 'http://essentia:8000', 'analysis.structure_url' => null, 'analysis.stems_url' => null]);

        $this->dir = sys_get_temp_dir().'/remoment-mood-'.uniqid();
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

    /** A 1280-number vector pointing at one axis (the same index = the same sound), so cosine similarity is 1 or 0. */
    private function vector(int $axis, float $tilt = 0.0): array
    {
        $v = array_fill(0, Embeddings::DIMENSIONS, 0.0);
        $v[$axis] = 1.0;
        if ($tilt > 0) {
            $v[($axis + 1) % Embeddings::DIMENSIONS] = $tilt;
        }

        return $v;
    }

    /** @return array{0: File, 1: Track} */
    private function localTrack(string $name = 'Song', string $hash = 'h1', ?array $sections = self::SECTIONS, string $artistName = 'Band'): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->dir], 'role' => 'library']);
        $artist = Artist::firstOrCreate(['name' => $artistName], ['source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);

        file_put_contents("{$this->dir}/{$name}-{$hash}.flac", 'not really audio');
        $file = File::create(['storage_location_id' => $location->id, 'path' => "{$name}-{$hash}.flac", 'hash' => $hash, 'size' => 16, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $name, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        if ($sections !== null) {
            $analysis = FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $hash, 'sections' => $sections]);
            $analysis->recordSuccess('structure', '1');
            $analysis->save();
        }

        return [$file, $track];
    }

    private function runJob(File $file, string $kind): void
    {
        (new AnalyzeTrackDeep($file, $kind))->handle();
    }

    /** Stores section vectors directly: `$axes` is the axis of each section. */
    private function embed(File $file, array $axes, string $hash = 'h1'): void
    {
        $items = self::SECTIONS['items'];
        SectionEmbeddings::store($file, $hash, array_map(fn ($axis, $i) => ['start' => $items[$i]['start'], 'end' => $items[$i]['end'], 'label' => $items[$i]['label'], 'embedding' => $this->vector($axis)], $axes, array_keys($axes)));
    }

    // --- mood over time ------------------------------------------------------------

    public function test_the_mood_stage_stores_the_curves_as_clamped_integers(): void
    {
        [$file, $track] = $this->localTrack(sections: null);

        $this->runJob($file, 'mood');

        $stored = $file->analysis()->first()->mood_curve;
        $this->assertSame([10, 50, 90, 100, 0], $stored['arousal']);
        $this->assertSame([30, 30, 60, 60, 50], $stored['valence']);
        $this->assertEquals(3.0, $stored['window']);
        $this->assertSame('done', DeepAnalysis::state($file, $file->analysis()->first(), 'mood')['state']);
        $this->assertSame([10, 50, 90, 100, 0], $track->fresh()->moodCurve()['arousal']);
        $this->assertSame($stored['arousal'], $track->fresh()->details()['mood_curve']['arousal']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/curve') && $r->isMultipart());
    }

    public function test_an_unusable_curve_is_a_failure_and_stores_nothing(): void
    {
        $this->curveAnswer = [['arousal' => [0.1, 0.2], 'valence' => [0.1]], 200];
        [$file] = $this->localTrack(sections: null);

        $this->runJob($file, 'mood');

        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'mood');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('usable mood curve', $state['message']);
        $this->assertNull($file->analysis()->first()->mood_curve);

        $this->curveAnswer = [['detail' => 'The mood models (msd-musicnn, deam) are not loaded'], 503];
        $this->runJob($file, 'mood');
        $this->assertStringContainsString('HTTP 503', DeepAnalysis::state($file, $file->analysis()->first(), 'mood')['message']);
    }

    public function test_mood_is_offered_only_when_the_sidecar_has_the_deam_model(): void
    {
        $this->assertTrue(DeepAnalysis::available('mood'));
        $this->assertTrue(DeepAnalysis::available('section_sound'));

        Cache::flush();
        $this->health = ['ok' => true, 'ml' => false, 'models' => []];
        $this->assertFalse(DeepAnalysis::available('mood'));
        $this->assertFalse(DeepAnalysis::available('section_sound'));

        Cache::flush();
        $this->health = ['ok' => true, 'ml' => true, 'models' => ['effnet']];
        $this->assertFalse(DeepAnalysis::available('mood'));
        $this->assertTrue(DeepAnalysis::available('section_sound'));
    }

    public function test_the_api_requests_and_reports_mood_and_estimates_it(): void
    {
        Queue::fake();
        [, $track] = $this->localTrack(sections: null);
        $headers = ['Authorization' => 'Bearer '.$this->token];

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['mood']], $headers)
            ->assertStatus(202)->assertJsonPath('queued', ['mood'])->assertJsonStructure(['estimate' => ['mood_seconds', 'section_sound_seconds']]);
        Queue::assertPushedOn(config('analysis.queue'), AnalyzeTrackDeep::class);

        $this->getJson("/api/library/tracks/{$track->id}/deep-analysis", $headers)
            ->assertOk()->assertJsonPath('kinds.mood.state', 'queued')->assertJsonPath('kinds.mood.available', true);
    }

    public function test_mood_is_not_available_without_the_ml_sidecar(): void
    {
        $this->health = ['ok' => true, 'ml' => false, 'models' => []];
        [, $track] = $this->localTrack(sections: null);

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['mood']], ['Authorization' => 'Bearer '.$this->token])
            ->assertStatus(409)->assertJsonPath('status', 'not_available');
    }

    public function test_now_playing_carries_the_mood_curve_only_for_an_analysed_track(): void
    {
        [$file] = $this->localTrack(sections: null);
        $this->runJob($file, 'mood');
        $device = Device::factory()->create();
        DeviceCache::updateState($device->id, State::Playing);
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(track: new TrackData(name: 'Song', artist: new ArtistData(name: 'Band'), duration: 200)));

        $this->getJson("/api/devices/{$device->id}")->assertOk()
            ->assertJsonPath('data.now_playing.mood_curve.arousal', [10, 50, 90, 100, 0])
            ->assertJsonPath('data.now_playing.mood_curve.window', 3);

        $other = Device::factory()->create();
        DeviceCache::updateState($other->id, State::Playing);
        DeviceCache::updateNowPlaying($other->id, new NowPlaying(track: new TrackData(name: 'Elsewhere', artist: new ArtistData(name: 'Someone'), duration: 100)));
        $this->assertArrayNotHasKey('mood_curve', $this->getJson("/api/devices/{$other->id}")->json('data.now_playing'));
    }

    // --- the strip and the sentence ---------------------------------------------

    public function test_columns_average_and_opacity_never_vanishes(): void
    {
        $this->assertSame([10, 30], MoodCurve::columns([0, 20, 20, 40], 2));
        $this->assertSame([5, 6, 7], MoodCurve::columns([5, 6, 7], 300));
        $this->assertSame([], MoodCurve::columns([]));
        $this->assertSame(0.12, MoodCurve::opacity(0));
        $this->assertSame(0.9, MoodCurve::opacity(100));
        $this->assertSame(0.9, MoodCurve::opacity(250));
    }

    public function test_the_mean_per_section_and_the_summary_sentence(): void
    {
        $curve = ['window' => 10.0, 'arousal' => [...array_fill(0, 6, 10), ...array_fill(0, 8, 80), ...array_fill(0, 6, 20)], 'valence' => array_fill(0, 20, 50)];
        $per = MoodCurve::perSection($curve, self::SECTIONS['items']);

        $this->assertSame([10, 80, 20], array_column($per, 'arousal'));
        $this->assertSame('Most intense: B (1:00 – 2:20). Calmest: A (0:00 – 1:00).', MoodCurve::summary($per));
        $this->assertNull(MoodCurve::summary(array_slice($per, 0, 1)), 'one section says nothing');
        $this->assertNull(MoodCurve::summary(MoodCurve::perSection(['window' => 10.0, 'arousal' => array_fill(0, 20, 40), 'valence' => array_fill(0, 20, 40)], self::SECTIONS['items'])), 'no real difference, no claim');
    }

    public function test_the_strip_draws_one_row_for_the_player_bar_and_two_for_the_details(): void
    {
        $curve = ['window' => 3.0, 'arousal' => [10, 90, 50], 'valence' => [20, 40, 60]];

        $two = Blade::render('<x-mood-strip :curve="$c" :duration="9" />', ['c' => $curve]);
        $this->assertSame(2, substr_count($two, 'data-row='));
        $this->assertSame(6, substr_count($two, 'data-value='));

        $one = Blade::render('<x-mood-strip :curve="$c" :duration="9" only="arousal" :seek="true" />', ['c' => $curve]);
        $this->assertSame(1, substr_count($one, 'data-row='));
        $this->assertStringContainsString('seekToSeconds(3)', $one);
    }

    public function test_the_mood_block_shows_the_strip_to_a_guest_and_the_button_only_to_the_admin(): void
    {
        [$file, $track] = $this->localTrack();
        $file->analysis()->first()->forceFill(['mood_curve' => ['method' => 'x', 'window' => 10, 'arousal' => [...array_fill(0, 6, 10), ...array_fill(0, 8, 80), ...array_fill(0, 6, 20)], 'valence' => array_fill(0, 20, 50)]])->save();

        Livewire::test(TrackMood::class, ['trackId' => $track->id])->assertSeeHtml('data-test="mood-strip"')->assertSee('Most intense')->assertDontSee('Analyse mood');

        $file->analysis()->first()->forceFill(['mood_curve' => null])->save();
        Cache::flush();
        Livewire::test(TrackMood::class, ['trackId' => $track->id])->assertSee('Not analysed')->assertDontSeeHtml('data-test="mood-analyse"');

        Livewire::actingAs(User::first())->test(TrackMood::class, ['trackId' => $track->id])->assertSeeHtml('data-test="mood-analyse"');
    }

    public function test_requesting_mood_from_the_block_needs_the_admin_and_queues_one_job(): void
    {
        Queue::fake();
        [, $track] = $this->localTrack(sections: null);

        Livewire::test(TrackMood::class, ['trackId' => $track->id])->call('analyse')->assertForbidden();
        Livewire::actingAs(User::first())->test(TrackMood::class, ['trackId' => $track->id])->call('analyse')->assertSeeHtml('data-test="mood-queued"');
        Queue::assertPushed(AnalyzeTrackDeep::class, 1);
    }

    // --- the sound of the sections -----------------------------------------------

    public function test_the_section_sound_stage_stores_one_vector_per_section(): void
    {
        [$file] = $this->localTrack();

        $this->runJob($file, 'section_sound');

        $analysis = $file->analysis()->first();
        $this->assertSame('done', DeepAnalysis::state($file, $analysis, 'section_sound')['state']);
        $this->assertSame(Embeddings::MODEL, $analysis->section_sound['model']);
        $this->assertCount(3, $analysis->section_sound['items']);
        $rows = SectionEmbedding::where('file_id', $file->id)->orderBy('position')->get();
        $this->assertSame([0, 1, 2], $rows->pluck('position')->all());
        $this->assertSame(['A', 'B', 'A'], $rows->pluck('label')->all());
        $this->assertSame(Embeddings::DIMENSIONS * 4, strlen($rows[0]->vector));
        $this->assertSame('h1', $rows[0]->content_hash);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/section-embeddings') && $r->isMultipart());
    }

    public function test_the_section_sound_needs_the_sections_first(): void
    {
        [$file, $track] = $this->localTrack(sections: null);

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['section_sound']], ['Authorization' => 'Bearer '.$this->token])
            ->assertStatus(422)->assertJsonPath('error', 'sections_required');

        $this->runJob($file, 'section_sound');
        $state = DeepAnalysis::state($file, $file->analysis()->first(), 'section_sound');
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('sections first', $state['message']);
        $this->assertSame(0, SectionEmbedding::count());
    }

    public function test_sections_and_their_sound_can_be_asked_for_together_and_sections_queue_first(): void
    {
        Queue::fake();
        [, $track] = $this->localTrack(sections: null);
        config(['analysis.structure_url' => 'http://structure:8091']);
        Http::fake(['structure:8091/health' => Http::response(['ok' => true])]);
        Cache::flush();

        $this->postJson("/api/library/tracks/{$track->id}/deep-analysis", ['kinds' => ['section_sound', 'sections']], ['Authorization' => 'Bearer '.$this->token])
            ->assertStatus(202)->assertJsonPath('queued', ['sections', 'section_sound']);
    }

    public function test_a_service_that_answers_a_wrong_count_or_a_broken_vector_stores_nothing(): void
    {
        [$file] = $this->localTrack();
        $this->sectionAnswer = ['sections' => [['embedding' => [1, 2, 3]]]];

        $this->runJob($file, 'section_sound');
        $this->assertStringContainsString('different number', DeepAnalysis::state($file, $file->analysis()->first(), 'section_sound')['message']);

        $this->sectionAnswer = ['sections' => array_fill(0, 3, ['embedding' => [1, 2, 3]])];
        $this->runJob($file, 'section_sound');
        $this->assertStringContainsString('cannot be used', DeepAnalysis::state($file, $file->analysis()->first(), 'section_sound')['message']);
        $this->assertSame(0, SectionEmbedding::count());
    }

    public function test_removing_the_sections_removes_their_sound_and_removing_the_sound_removes_the_rows(): void
    {
        [$file] = $this->localTrack();
        $this->runJob($file, 'section_sound');
        $this->assertSame(3, SectionEmbedding::count());

        $this->assertTrue(DeepAnalysis::forget($file, 'sections'));

        $analysis = $file->analysis()->first();
        $this->assertNull($analysis->sections);
        $this->assertNull($analysis->section_sound);
        $this->assertNull($analysis->stages['section_sound'] ?? null);
        $this->assertSame(0, SectionEmbedding::count());

        [$other] = $this->localTrack('Other', 'h2');
        $this->runJob($other, 'section_sound');
        $this->assertTrue(DeepAnalysis::forget($other, 'section_sound'));
        $this->assertSame(0, SectionEmbedding::count());
        $this->assertNotNull($other->analysis()->first()->sections, 'the sections stay');
    }

    public function test_matches_are_the_best_section_of_each_other_track_most_alike_first(): void
    {
        [$seedFile, $seed] = $this->localTrack('Seed', 'h1');
        [$twinFile, $twin] = $this->localTrack('Twin', 'h2', artistName: 'Other Band');
        [$farFile, $far] = $this->localTrack('Far', 'h3', artistName: 'Third Band');
        [$cousinFile, $cousin] = $this->localTrack('Cousin', 'h4', artistName: 'Fourth Band');
        $this->embed($seedFile, [0, 1, 2], 'h1');
        $this->embed($twinFile, [5, 0, 6], 'h2');      // its section 1 sounds exactly like the seed's section 0
        $this->embed($farFile, [7, 8, 9], 'h3');
        SectionEmbeddings::store($cousinFile, 'h4', [['start' => 0, 'end' => 60, 'label' => 'A', 'embedding' => $this->vector(0, 0.5)], ['start' => 60, 'end' => 90, 'label' => null, 'embedding' => $this->vector(0, 2.0)]]);

        $matches = (new SimilarSections($seed))->matches(0, 10);

        $this->assertSame([$twin->id, $cousin->id, $far->id], array_column($matches, 'track_id'));
        $this->assertSame(1, $matches[0]['section']['index']);
        $this->assertEquals(1.0, $matches[0]['similarity']);
        $this->assertSame(0, $matches[1]['section']['index'], 'the closer of the cousin\'s two sections');
        $this->assertEqualsWithDelta(0.8944, $matches[1]['similarity'], 0.001);
        $this->assertEquals(0.0, $matches[2]['similarity']);
        $this->assertNotContains($seed->id, array_column($matches, 'track_id'), 'never the track itself');
        $this->assertCount(2, (new SimilarSections($seed))->matches(0, 2), 'the limit');
        $this->assertNull((new SimilarSections($seed))->matches(7, 10), 'no such section');
    }

    public function test_the_same_song_by_the_same_artist_and_a_changed_file_are_not_matches(): void
    {
        [$seedFile, $seed] = $this->localTrack('Seed', 'h1');
        [$dupFile] = $this->localTrack('Seed', 'h2');                               // same artist and title, another file
        [$staleFile, $stale] = $this->localTrack('Stale', 'h3', artistName: 'Else');
        $this->embed($seedFile, [0, 1, 2], 'h1');
        $this->embed($dupFile, [0, 1, 2], 'h2');
        $this->embed($staleFile, [0, 1, 2], 'old-hash');                           // made from an earlier content of the file

        $this->assertSame([], (new SimilarSections($seed))->matches(0, 10));
        $this->assertSame([], (new SimilarSections($stale))->sections(), 'a changed file has no usable vectors');
    }

    public function test_the_similar_sections_api_answers_matches_and_the_two_errors(): void
    {
        [$seedFile, $seed] = $this->localTrack('Seed', 'h1');
        [$twinFile, $twin] = $this->localTrack('Twin', 'h2', artistName: 'Other Band');
        [, $plain] = $this->localTrack('Plain', 'h3', artistName: 'Third Band');
        $this->embed($seedFile, [0, 1, 2], 'h1');
        $this->embed($twinFile, [5, 0, 6], 'h2');

        $this->getJson("/api/library/tracks/{$seed->id}/similar-sections?section=0")
            ->assertOk()
            ->assertJsonPath('canonical_id', $seed->id)
            ->assertJsonPath('section.index', 0)
            ->assertJsonCount(3, 'sections')
            ->assertJsonPath('data.0.track.id', $twin->id)
            ->assertJsonPath('data.0.section.index', 1)
            ->assertJsonPath('data.0.similarity', 1);

        $this->getJson("/api/library/tracks/{$seed->id}/similar-sections")->assertOk()->assertJsonPath('section.index', 0);
        $this->getJson("/api/library/tracks/{$seed->id}/similar-sections?section=9")->assertStatus(404)->assertJsonPath('error', 'unknown_section');
        $this->getJson("/api/library/tracks/{$plain->id}/similar-sections")->assertStatus(422)->assertJsonPath('error', 'not_analysed')->assertJsonPath('state', 'none');
        $this->getJson("/api/library/tracks/{$seed->id}/similar-sections?limit=999")->assertStatus(422);
    }

    public function test_the_mcp_tool_lists_similar_sections_and_requests_the_new_kinds(): void
    {
        Queue::fake();
        [$seedFile, $seed] = $this->localTrack('Seed', 'h1');
        [$twinFile] = $this->localTrack('Twin', 'h2', artistName: 'Other Band');
        $this->embed($seedFile, [0, 1, 2], 'h1');
        $this->embed($twinFile, [5, 0, 6], 'h2');
        request()->headers->set('Authorization', 'Bearer '.$this->token);

        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'similar_sections', 'track_id' => $seed->id, 'section' => 0, 'limit' => 5])
            ->assertOk()->assertSee('Twin')->assertSee('similarity');
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'request', 'track_id' => $seed->id, 'kinds' => ['mood']])
            ->assertOk()->assertSee('queued')->assertSee('mood_seconds');
    }

    public function test_the_section_block_shows_matches_with_the_picker_and_the_admin_button(): void
    {
        [$file, $seed] = $this->localTrack('Seed', 'h1');
        [$twinFile] = $this->localTrack('Twin', 'h2', artistName: 'Other Band');

        Livewire::test(SectionSimilar::class, ['trackId' => $seed->id])->assertSee('Not analysed');
        Livewire::actingAs(User::first())->test(SectionSimilar::class, ['trackId' => $seed->id])->assertSeeHtml('data-test="section-sound-analyse"');
        auth()->logout();

        $this->embed($file, [0, 1, 2], 'h1');
        $this->embed($twinFile, [5, 0, 6], 'h2');

        Livewire::test(SectionSimilar::class, ['trackId' => $seed->id])
            ->assertSeeHtml('data-test="section-pick"')->assertSee('Twin')->assertSee('100%')
            ->set('section', 2)->assertSee('Twin')->assertSee('0%')->assertDontSee('100%');
    }

    public function test_without_sections_the_block_says_so(): void
    {
        [, $track] = $this->localTrack(sections: null);

        Livewire::actingAs(User::first())->test(SectionSimilar::class, ['trackId' => $track->id])->assertSeeHtml('data-test="section-similar-needs-sections"');
    }

    // --- the lanes: a tap on a section marks it in every lane -------------------------

    public function test_the_stem_lanes_mark_the_tapped_section_in_every_lane(): void
    {
        $stems = ['method' => 'htdemucs', 'rate_hz' => 2, 'vocals' => array_fill(0, 20, -30), 'drums' => array_fill(0, 20, -20), 'bass' => array_fill(0, 20, -25), 'other' => array_fill(0, 20, -35)];
        $sections = [['start' => 0, 'end' => 4, 'label' => 'A', 'confidence' => 0.9], ['start' => 4, 'end' => 10, 'label' => null, 'confidence' => 0]];

        $html = Blade::render('<x-stem-lanes :stems="$s" :sections="$sections" :duration="10" />', ['s' => $stems, 'sections' => $sections]);

        $this->assertSame(2, substr_count($html, 'data-section="'), 'both blocks of the strip are tappable');
        $this->assertSame(4, substr_count($html, 'data-section-band'), 'a band in each of the four lanes');
        $this->assertStringContainsString('Tap a section to mark it in every lane', $html);
        $this->assertStringContainsString('"left":40', str_replace('\\u0022', '"', $html));

        $without = Blade::render('<x-stem-lanes :stems="$s" :duration="10" />', ['s' => $stems]);
        $this->assertStringNotContainsString('data-section-band', $without);
    }

    public function test_a_plain_strip_is_not_tappable(): void
    {
        $html = Blade::render('<x-section-strip :sections="$s" :duration="10" />', ['s' => [['start' => 0, 'end' => 10, 'label' => null, 'confidence' => 0]]]);

        $this->assertStringNotContainsString('sel =', $html);
    }
}
