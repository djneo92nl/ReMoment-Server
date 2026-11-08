<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Jobs\ExportLibrarySelection;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools as McpTools;
use App\Models\AdminToken;
use App\Models\Export;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The export API (workstream 13, step 3): estimate, start, poll, signed download, cancel, MCP, gating. */
class ExportApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $library;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Tools::forget();
        // Any runnable binary stands in for ffmpeg where the job is only queued.
        config(['analysis.ffmpeg' => '/bin/echo', 'export.nice' => 0]);
        $this->library = sys_get_temp_dir().'/remoment-export-api-'.uniqid();
        mkdir($this->library, 0775, true);
        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->library));
        Tools::forget();
        parent::tearDown();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function album(string $name = 'LP'): Album
    {
        $artist = Artist::firstOrCreate(['name' => 'Band']);

        return Album::create(['artist_id' => $artist->id, 'name' => $name]);
    }

    /** A track with a synthetic file row (no audio on disk) or none at all. */
    private function track(Album $album, string $name, ?string $codec = 'mp3', int $kbps = 128, int $size = 4000): Track
    {
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $album->artist_id, 'external_id' => uniqid('local:'), 'name' => $name, 'duration' => 200, 'source' => 'files']);
        if ($codec) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->library], 'role' => 'library']);
            $f = File::create([
                'storage_location_id' => $location->id, 'path' => uniqid().'.'.$codec, 'hash' => uniqid(), 'size' => $size, 'mtime' => 1,
                'extension' => $codec, 'codec' => $codec, 'bitrate' => $kbps, 'sample_rate' => 44100, 'channels' => 2, 'duration' => 200, 'status' => File::PRESENT,
            ]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $f->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        }

        return $track;
    }

    public function test_estimate_for_each_type(): void
    {
        $album = $this->album();
        $a = $this->track($album, 'One');
        $b = $this->track($album, 'Two', 'flac', 900);
        $c = $this->track($album, 'Streamed', null);
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2]]);

        $album = $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id, 'profile' => 'mp3-320'], $this->auth())->assertOk();
        $album->assertJsonPath('title', 'LP')->assertJsonPath('totals.tracks', 3)->assertJsonPath('totals.copied', 1)
            ->assertJsonPath('totals.transcoded', 1)->assertJsonPath('totals.skipped', 1)->assertJsonPath('totals.exportable', 2)
            ->assertJsonPath('skipped.0.track_id', $c->id)->assertJsonPath('skipped.0.reason', 'no_own_file');

        $this->postJson('/api/library/exports/estimate', ['type' => 'playlist', 'id' => $playlist->id], $this->auth())
            ->assertOk()->assertJsonPath('totals.tracks', 2)->assertJsonPath('totals.profile', 'mp3-320');
        $this->postJson('/api/library/exports/estimate', ['type' => 'artist', 'id' => Artist::first()->id, 'profile' => 'highest'], $this->auth())
            ->assertOk()->assertJsonPath('totals.copied', 2)->assertJsonPath('totals.transcoded', 0);
        $this->postJson('/api/library/exports/estimate', ['type' => 'tracks', 'track_ids' => [$b->id, $a->id]], $this->auth())
            ->assertOk()->assertJsonPath('totals.tracks', 2)->assertJsonPath('selection.track_ids', [$b->id, $a->id]);

        $this->assertSame(0, Export::count());
    }

    public function test_validation_and_unknown_ids(): void
    {
        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => 999], $this->auth())->assertStatus(422);
        $this->postJson('/api/library/exports/estimate', ['type' => 'zip'], $this->auth())->assertStatus(422);
        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $this->album()->id, 'profile' => 'wav'], $this->auth())->assertStatus(422);
        $this->postJson('/api/library/exports/estimate', ['type' => 'tracks', 'track_ids' => [12345]], $this->auth())->assertStatus(422);
    }

    public function test_alias_ids_resolve(): void
    {
        $album = $this->album();
        $t = $this->track($album, 'One');
        DB::table('id_aliases')->insert([
            ['entity' => 'album', 'old_id' => 7001, 'new_id' => $album->id, 'merged_at' => now()],
            ['entity' => 'track', 'old_id' => 7002, 'new_id' => $t->id, 'merged_at' => now()],
        ]);

        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => 7001], $this->auth())
            ->assertOk()->assertJsonPath('selection.id', $album->id)->assertJsonPath('totals.tracks', 1);
        $this->postJson('/api/library/exports/estimate', ['type' => 'tracks', 'track_ids' => [7002]], $this->auth())
            ->assertOk()->assertJsonPath('selection.track_ids', [$t->id]);
    }

    public function test_start_queues_the_job_and_poll_shows_the_plan(): void
    {
        Queue::fake();
        $album = $this->album();
        $this->track($album, 'One');
        $this->track($album, 'Streamed', null);

        $id = $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id], $this->auth())
            ->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');

        Queue::assertPushed(ExportLibrarySelection::class, 1);
        $this->getJson("/api/library/exports/{$id}", $this->auth())->assertOk()
            ->assertJsonPath('status', 'queued')->assertJsonPath('progress.files_total', 1)->assertJsonPath('progress.files_done', 0)
            ->assertJsonPath('totals.tracks', 2)->assertJsonPath('skipped.0.reason', 'no_own_file')->assertJsonPath('parts', []);
        $this->getJson('/api/library/exports', $this->auth())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/library/exports/nope', $this->auth())->assertNotFound();
    }

    public function test_over_the_limit_is_refused_with_the_estimate(): void
    {
        Queue::fake();
        config(['export.max_gb' => 0.000001]);   // about 1 KB
        $album = $this->album();
        $this->track($album, 'Big', 'flac', 900, 5_000_000);

        $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id, 'profile' => 'highest'], $this->auth())
            ->assertStatus(422)->assertJsonPath('error', 'export_too_large')->assertJsonPath('estimate.totals.exceeds', ['max_gb']);
        Queue::assertNothingPushed();
        $this->assertSame(0, Export::count());
    }

    public function test_nothing_to_export_and_missing_ffmpeg(): void
    {
        Queue::fake();
        $album = $this->album();
        $this->track($album, 'Streamed', null);
        $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id], $this->auth())
            ->assertStatus(422)->assertJsonPath('error', 'nothing_to_export');

        $this->track($album, 'Local');
        config(['analysis.ffmpeg' => '/nonexistent/ffmpeg']);
        Tools::forget();
        $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id], $this->auth())
            ->assertStatus(409)->assertJsonPath('error', 'not_available');
        Queue::assertNothingPushed();
    }

    private function finished(string $content = 'zipdata-0123456789'): Export
    {
        $export = Export::create([
            'selection_type' => 'album', 'selection_id' => $this->album()->id, 'profile' => 'mp3-320', 'status' => Export::DONE,
            'files_total' => 1, 'files_done' => 1, 'part_files' => [['name' => 'LP.zip', 'size' => strlen($content), 'files' => 1]],
            'finished_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($export->directory().'/LP.zip', $content);

        return $export;
    }

    public function test_signed_download_works_and_unsigned_or_tampered_is_forbidden(): void
    {
        $export = $this->finished();

        $url = $this->getJson("/api/library/exports/{$export->public_id}", $this->auth())->assertOk()
            ->assertJsonPath('status', 'done')->assertJsonPath('progress.percent', 100)->assertJsonPath('parts.0.part', 1)
            ->assertJsonPath('parts.0.name', 'LP.zip')->json('parts.0.url');
        $this->assertStringStartsWith("/api/library/exports/{$export->public_id}/download/1?", $url);

        // No token header: the signature is the authorization.
        $response = $this->get($url)->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('LP.zip', $response->headers->get('Content-Disposition'));
        $this->assertSame('zipdata-0123456789', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get($url, ['Range' => 'bytes=0-6'])->assertStatus(206);

        $this->get("/api/library/exports/{$export->public_id}/download/1")->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->get(str_replace('/download/1', '/download/2', $url))->assertForbidden();

        $this->travel(\App\Http\Controllers\Api\ExportController::URL_TTL + 5)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_download_of_an_unfinished_export_is_404(): void
    {
        $export = Export::create(['selection_type' => 'album', 'selection_id' => 1, 'profile' => 'mp3-320', 'status' => Export::RUNNING]);
        $url = \URL::temporarySignedRoute('api.library.exports.download', now()->addMinute(), ['id' => $export->public_id, 'part' => 1], absolute: false);
        $this->get($url)->assertNotFound();
    }

    public function test_delete_while_queued_and_after_done(): void
    {
        Queue::fake();
        $album = $this->album();
        $this->track($album, 'One');
        $queued = $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id], $this->auth())->json('id');
        $this->deleteJson("/api/library/exports/{$queued}", [], $this->auth())->assertOk()->assertJsonPath('result', 'cancelled');
        $this->getJson("/api/library/exports/{$queued}", $this->auth())->assertNotFound();

        $export = $this->finished();
        $this->deleteJson("/api/library/exports/{$export->public_id}", [], $this->auth())->assertOk()->assertJsonPath('result', 'removed');
        Storage::disk('local')->assertMissing($export->directory().'/LP.zip');
        $this->assertSame(0, Export::count());
        $this->deleteJson("/api/library/exports/{$export->public_id}", [], $this->auth())->assertNotFound();
    }

    public function test_a_real_run_through_the_api(): void
    {
        config(['analysis.ffmpeg' => 'ffmpeg']);
        Tools::forget();
        $probe = Process::run(['ffmpeg', '-hide_banner', '-encoders']);
        if (!$probe->successful() || !str_contains($probe->output(), 'libmp3lame')) {
            $this->markTestSkipped('ffmpeg with libmp3lame is not available.');
        }

        $path = "{$this->library}/a.mp3";
        $r = Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'libmp3lame', '-b:a', '128k', $path]);
        $this->assertTrue($r->successful());
        $album = $this->album();
        $track = $this->track($album, 'Tone');
        File::query()->update(['path' => 'a.mp3', 'size' => filesize($path)]);

        $id = $this->postJson('/api/library/exports', ['type' => 'tracks', 'track_ids' => [$track->id]], $this->auth())->assertStatus(202)->json('id');
        $this->getJson("/api/library/exports/{$id}", $this->auth())->assertJsonPath('status', 'done')->assertJsonPath('parts.0.files', 1);
    }

    public function test_the_endpoints_need_the_admin_token(): void
    {
        $album = $this->album();
        $export = $this->finished();
        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id])->assertUnauthorized();
        $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id])->assertUnauthorized();
        $this->getJson('/api/library/exports')->assertUnauthorized();
        $this->getJson("/api/library/exports/{$export->public_id}")->assertUnauthorized();
        $this->deleteJson("/api/library/exports/{$export->public_id}")->assertUnauthorized();
    }

    public function test_control_profile_answers_not_available(): void
    {
        config(['profile.current' => 'control']);
        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => 1], $this->auth())
            ->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->get('/api/library/exports/x/download/1')->assertStatus(422);
        $this->getJson('/api/info')->assertJsonMissingPath('features.export');
    }

    public function test_info_lists_the_export_feature(): void
    {
        $this->getJson('/api/info')->assertJsonPath('features.export', true);
        config(['analysis.ffmpeg' => '/nonexistent/ffmpeg']);
        Tools::forget();
        $this->getJson('/api/info')->assertJsonMissingPath('features.export');
    }

    public function test_the_mcp_tool_is_admin_only_and_goes_through_the_api(): void
    {
        ReMomentServer::tool(McpTools\Export::class, ['action' => 'list'])->assertNotRegistered();

        Queue::fake();
        $album = $this->album();
        $this->track($album, 'One');
        request()->headers->set('Authorization', 'Bearer '.$this->token);

        ReMomentServer::tool(McpTools\Export::class, ['action' => 'estimate', 'type' => 'album', 'id' => (string) $album->id])
            ->assertOk()->assertSee('exportable');
        ReMomentServer::tool(McpTools\Export::class, ['action' => 'start', 'type' => 'album', 'id' => (string) $album->id, 'profile' => 'compat'])
            ->assertOk()->assertSee('queued');
        $id = Export::first()->public_id;
        ReMomentServer::tool(McpTools\Export::class, ['action' => 'status', 'id' => $id])->assertOk()->assertSee('compat');
        ReMomentServer::tool(McpTools\Export::class, ['action' => 'list'])->assertOk()->assertSee($id);
        ReMomentServer::tool(McpTools\Export::class, ['action' => 'cancel', 'id' => $id])->assertOk()->assertSee('cancelled');
        ReMomentServer::tool(McpTools\Export::class, ['action' => 'status', 'id' => $id])->assertHasErrors();
    }
}
