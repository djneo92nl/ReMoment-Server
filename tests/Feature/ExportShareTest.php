<?php

namespace Tests\Feature;

use App\Domain\Export\ExportRetention;
use App\Domain\Export\ExportService;
use App\Domain\Export\ExportShare;
use App\Jobs\ExportLibrarySelection;
use App\Models\Export;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The `exports` SMB share's side in the app: finished exports are also put in EXPORT_SHARE_DIR and go with the retention. */
class ExportShareTest extends TestCase
{
    use RefreshDatabase;

    private string $share;

    private string $library;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $base = sys_get_temp_dir().'/remoment-share-test-'.uniqid();
        $this->share = "{$base}/share";
        $this->library = "{$base}/library";
        mkdir($this->share, 0775, true);
        mkdir($this->library, 0775, true);
        config(['export.share_dir' => $this->share, 'export.nice' => 0]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg(dirname($this->share)));
        parent::tearDown();
    }

    private function done(string $title = 'Some Album', int $hours = 2): Export
    {
        $artist = Artist::create(['name' => 'A']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $title]);
        $e = Export::create(['selection_type' => 'album', 'selection_id' => $album->id, 'profile' => 'mp3-320', 'status' => 'done', 'finished_at' => now()->subHours($hours), 'expires_at' => now()->subHours($hours)->addHours(24), 'part_files' => [['name' => "{$title}.zip", 'size' => 5, 'files' => 1]]]);
        Storage::disk('local')->put($e->directory()."/{$title}.zip", 'zipxx');

        return $e;
    }

    private function publish(Export $e): void
    {
        (new ExportShare)->publish($e, Storage::disk('local')->path($e->directory()), $e->part_files);
    }

    /** @return list<string> */
    private function folders(): array
    {
        return array_values(array_diff(scandir($this->share), ['.', '..']));
    }

    public function test_it_is_off_without_a_directory(): void
    {
        config(['export.share_dir' => null]);
        $e = $this->done();
        $this->publish($e);

        $this->assertFalse(ExportShare::enabled());
        $this->assertSame([], $this->folders());
        $this->assertFalse((new ExportShare)->remove($e));
    }

    public function test_a_finished_export_gets_a_folder_with_its_files(): void
    {
        $e = $this->done('Some Album');
        $this->publish($e);

        $folder = 'Some Album - '.substr($e->public_id, 0, 8);
        $this->assertSame([$folder], $this->folders());
        $file = "{$this->share}/{$folder}/Some Album.zip";
        $this->assertSame('zipxx', file_get_contents($file));
        $this->assertSame(0644, fileperms($file) & 0777);
        $this->assertSame(0755, fileperms("{$this->share}/{$folder}") & 0777);

        // Publishing again replaces the folder, it does not duplicate it.
        $this->publish($e);
        $this->assertSame([$folder], $this->folders());
    }

    public function test_a_missing_share_directory_is_made_and_a_failure_never_throws(): void
    {
        config(['export.share_dir' => "{$this->share}/new/dir"]);
        $e = $this->done();
        $this->publish($e);
        $this->assertCount(1, array_diff(scandir("{$this->share}/new/dir"), ['.', '..']));

        // The source file is gone: nothing half-made is left, and no exception comes out.
        $gone = $this->done('Gone');
        Storage::disk('local')->delete($gone->directory().'/Gone.zip');
        $this->publish($gone);
        $this->assertCount(1, array_diff(scandir("{$this->share}/new/dir"), ['.', '..']));
    }

    public function test_retention_removes_the_folder_with_the_export(): void
    {
        $old = $this->done('Old', 30);
        $fresh = $this->done('Fresh', 2);
        $this->publish($old);
        $this->publish($fresh);
        mkdir("{$this->share}/My own folder");

        app(ExportRetention::class)->prune();

        $this->assertSame('expired', $old->fresh()->status);
        $names = $this->folders();
        $this->assertContains('Fresh - '.substr($fresh->public_id, 0, 8), $names);
        $this->assertNotContains('Old - '.substr($old->public_id, 0, 8), $names);
        $this->assertContains('My own folder', $names); // never touched
    }

    public function test_retention_sweeps_folders_of_unknown_exports_but_only_by_our_pattern_and_age(): void
    {
        mkdir("{$this->share}/Orphan - 0123abcd");
        touch("{$this->share}/Orphan - 0123abcd", time() - 7200);
        mkdir("{$this->share}/Fresh orphan - 0123abce");
        mkdir("{$this->share}/Holiday photos");
        touch("{$this->share}/Holiday photos", time() - 7200);
        mkdir("{$this->share}/Not ours - xyz");

        app(ExportRetention::class)->prune();

        $this->assertEqualsCanonicalizing(['Fresh orphan - 0123abce', 'Holiday photos', 'Not ours - xyz'], $this->folders());
    }

    public function test_cancelling_or_removing_an_export_removes_its_folder(): void
    {
        $e = $this->done();
        $this->publish($e);
        $this->assertCount(1, $this->folders());

        $this->assertSame('removed', app(ExportService::class)->cancel($e));
        $this->assertSame([], $this->folders());
    }

    public function test_the_export_job_puts_its_zip_on_the_share(): void
    {
        $r = Process::run(['ffmpeg', '-hide_banner', '-encoders']);
        if (!$r->successful() || !str_contains($r->output(), 'libmp3lame')) {
            $this->markTestSkipped('ffmpeg with libmp3lame is not available.');
        }
        $path = "{$this->library}/t.mp3";
        $made = Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'libmp3lame', '-b:a', '128k', $path]);
        $this->assertTrue($made->successful(), $made->errorOutput());

        $artist = Artist::create(['name' => 'The Artist']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Shared Album']);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => 'local:1', 'name' => 'Tone', 'duration' => 1, 'source' => 'files']);
        $location = StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->library], 'role' => 'library']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => 't.mp3', 'hash' => 'h', 'size' => filesize($path), 'mtime' => 1, 'extension' => 'mp3', 'codec' => 'mp3', 'bitrate' => 128, 'sample_rate' => 44100, 'channels' => 1, 'duration' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);

        $export = Export::create(['selection_type' => 'album', 'selection_id' => $album->id, 'profile' => 'mp3-320', 'status' => Export::QUEUED]);
        (new ExportLibrarySelection($export))->handle();
        $export = $export->fresh();

        $this->assertSame('done', $export->status, (string) $export->error);
        $this->assertSame(['Shared Album - '.substr($export->public_id, 0, 8)], $this->folders());
        $zip = "{$this->share}/".$this->folders()[0].'/'.$export->part_files[0]['name'];
        $this->assertFileExists($zip);
        $this->assertSame(filesize(Storage::disk('local')->path($export->directory().'/'.$export->part_files[0]['name'])), filesize($zip));
    }
}
