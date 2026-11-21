<?php

namespace Tests\Feature\Api;

use App\Domain\Analysis\AnalysisProgress;
use App\Jobs\AnalyzeAudioFile;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** /api/admin/analysis: the data and buttons of /settings/analysis (docs/api/admin-analysis.md). */
class AdminAnalysisApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        config(['analysis.enabled' => true, 'profile.current' => 'full']);
    }

    private function file(string $path = 'a.flac'): File
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);

        $file = File::create(['storage_location_id' => $location->id, 'path' => $path, 'hash' => 'h-'.$path, 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'name' => $path, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);

        return $file;
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $file = $this->file();

        $this->assertNeedsAdminToken([
            ['GET', '/api/admin/analysis'], ['GET', '/api/admin/analysis/problems'], ['POST', '/api/admin/analysis/run'],
            ['POST', "/api/admin/analysis/files/{$file->id}/analyse"], ['POST', "/api/admin/analysis/files/{$file->id}/retry"],
        ]);
        Queue::assertNothingPushed();
    }

    public function test_the_status_has_the_shape_of_the_page(): void
    {
        $this->file('a.flac');
        $this->file('b.flac');

        $response = $this->getJson('/api/admin/analysis', $this->adminHeaders())->assertOk();

        $response->assertJsonStructure([
            'enabled', 'files', 'complete', 'backlog', 'with_problems', 'queued_jobs', 'files_last_24h', 'last_activity_at', 'max_attempts',
            'schedule' => ['night_files', 'night_hours', 'seconds_per_file', 'nights_to_clear_backlog', 'backlog_hours'],
            'current', 'decode_seconds_per_file', 'sidecar',
            'stages' => [['key', 'description', 'version', 'enabled', 'reason', 'done', 'pending', 'stale', 'failed', 'gave_up', 'seconds_per_file']],
        ]);
        $response->assertJsonPath('enabled', true)->assertJsonPath('files', 2)->assertJsonPath('backlog', 2);
        $this->assertContains('loudness', array_column($response->json('stages'), 'key'));
    }

    public function test_problems_list_failed_stages_and_filter_by_stage(): void
    {
        $file = $this->file();
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $file->hash, 'errors' => [
            'loudness' => ['version' => '1', 'message' => 'decode failed', 'attempts' => 3],
            'waveform' => ['version' => '1', 'message' => 'oops', 'attempts' => 1],
        ]]);
        $headers = $this->adminHeaders();

        $all = $this->getJson('/api/admin/analysis/problems', $headers)->assertOk();
        $this->assertCount(2, $all->json('data'));
        $loudness = collect($all->json('data'))->firstWhere('stage', 'loudness');
        $this->assertSame(['file_id' => $file->id, 'path' => 'a.flac', 'stage' => 'loudness', 'message' => 'decode failed', 'attempts' => 3, 'gave_up' => true, 'at' => null], $loudness);

        $this->getJson('/api/admin/analysis/problems?stage=waveform', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/analysis/problems?stage=nope', $headers)->assertStatus(422);
    }

    public function test_run_queues_a_batch_and_reports_how_many(): void
    {
        $this->file('a.flac');
        $this->file('b.flac');

        $this->postJson('/api/admin/analysis/run', [], $this->adminHeaders())->assertOk()->assertJsonPath('queued', 2);

        Queue::assertPushed(AnalyzeAudioFile::class, 2);
    }

    public function test_analyse_and_retry_queue_a_file_or_one_stage(): void
    {
        $file = $this->file();
        $other = $this->file('b.flac');
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $file->hash, 'errors' => ['loudness' => ['version' => '1', 'message' => 'x', 'attempts' => 3]]]);
        $headers = $this->adminHeaders();

        $this->postJson("/api/admin/analysis/files/{$other->id}/analyse", [], $headers)->assertOk()->assertJsonPath('status', 'queued');
        Queue::assertPushed(AnalyzeAudioFile::class, 1);

        $this->postJson("/api/admin/analysis/files/{$file->id}/retry", [], $headers)->assertStatus(422);
        $this->postJson("/api/admin/analysis/files/{$file->id}/retry", ['stage' => 'loudness'], $headers)->assertOk()->assertJsonPath('stage', 'loudness');

        $this->assertArrayNotHasKey('loudness', FileAnalysis::first()->errors);
        Queue::assertPushed(AnalyzeAudioFile::class, 2);
    }

    public function test_retry_of_a_file_without_analysis_is_a_conflict_and_actions_stop_when_analysis_is_off(): void
    {
        $file = $this->file();
        $headers = $this->adminHeaders();

        $this->postJson("/api/admin/analysis/files/{$file->id}/retry", ['stage' => 'loudness'], $headers)->assertStatus(409)->assertJsonPath('error', 'not_analysed');

        config(['analysis.enabled' => false]);
        AnalysisProgress::forget();
        $this->postJson('/api/admin/analysis/run', [], $headers)->assertStatus(409)->assertJsonPath('error', 'analysis_disabled');
        $this->postJson("/api/admin/analysis/files/{$file->id}/analyse", [], $headers)->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_it_is_not_available_below_the_full_profile(): void
    {
        config(['profile.current' => 'standard']);

        $this->getJson('/api/admin/analysis', $this->adminHeaders())->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }
}
