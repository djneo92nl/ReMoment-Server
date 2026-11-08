<?php

namespace Tests\Feature;

use App\Domain\Export\ExportNames;
use App\Domain\Export\ZipWriter;
use App\Jobs\ExportLibrarySelection;
use App\Models\Export;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** The export job: plan to zip (workstream 13, step 2). Uses real ffmpeg for tiny synthetic files. */
class ExportJobTest extends TestCase
{
    use RefreshDatabase;

    private string $library;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->library = sys_get_temp_dir().'/remoment-export-test-'.uniqid();
        mkdir($this->library, 0775, true);
        config(['export.nice' => 0]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->library));
        parent::tearDown();
    }

    private function needFfmpeg(): void
    {
        $result = Process::run([config('analysis.ffmpeg'), '-hide_banner', '-encoders']);
        if (!$result->successful() || !str_contains($result->output(), 'libmp3lame')) {
            $this->markTestSkipped('ffmpeg with libmp3lame is not available.');
        }
    }

    /** A synthetic one-second tone in the given container, with a title tag. */
    private function audio(string $name, string $ext, ?string $bitrate = null, string $title = 'Tone'): string
    {
        $path = "{$this->library}/{$name}.{$ext}";
        $codec = $ext === 'flac' ? ['-c:a', 'flac'] : ['-c:a', 'libmp3lame', '-b:a', $bitrate ?? '128k'];
        $r = Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', ...$codec, '-metadata', "title={$title}", '-metadata', 'artist=Tagged Artist', $path]);
        $this->assertTrue($r->successful(), $r->errorOutput());

        return $path;
    }

    private function track(Album $album, string $name, ?string $file, string $codec, int $kbps, int $number = 1): Track
    {
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $album->artist_id, 'external_id' => uniqid('local:'), 'name' => $name, 'duration' => 1, 'source' => 'files']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'track_number', 'value' => (string) $number, 'type' => 'int', 'source' => 'files']);
        if ($file) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->library], 'role' => 'library']);
            $f = File::create([
                'storage_location_id' => $location->id, 'path' => basename($file), 'hash' => sha1($file), 'size' => filesize($file), 'mtime' => 1,
                'extension' => pathinfo($file, PATHINFO_EXTENSION), 'codec' => $codec, 'bitrate' => $kbps, 'sample_rate' => 44100, 'channels' => 1,
                'duration' => 1, 'status' => File::PRESENT,
            ]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $f->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        }

        return $track;
    }

    private function album(): Album
    {
        $artist = Artist::create(['name' => 'The Artist']);

        return Album::create(['artist_id' => $artist->id, 'name' => 'Some: Album?']);
    }

    private function export(string $type, int $id, string $profile = 'mp3-128'): Export
    {
        return Export::create(['selection_type' => $type, 'selection_id' => $id, 'profile' => $profile, 'status' => Export::QUEUED]);
    }

    private function runExport(Export $export): Export
    {
        (new ExportLibrarySelection($export))->handle();

        return $export->fresh();
    }

    private function zip(Export $export, int $part = 0): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->directory().'/'.$export->part_files[$part]['name'])) === true);

        return $zip;
    }

    /** @return list<string> */
    private function names(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        return $names;
    }

    public function test_album_export_copies_and_transcodes_into_one_zip(): void
    {
        $this->needFfmpeg();
        $album = $this->album();
        $copyPath = $this->audio('a', 'mp3', '128k');
        $this->track($album, 'Copied', $copyPath, 'mp3', 128, 1);
        $this->track($album, 'Transcoded', $this->audio('b', 'flac'), 'flac', 0, 2);
        $this->track($album, 'Streaming only', null, 'mp3', 0, 3);

        $export = $this->runExport($this->export('album', $album->id));

        $this->assertSame(Export::DONE, $export->status, (string) $export->error);
        $this->assertSame([1, 1, 1], [$export->copied, $export->transcoded, $export->skipped_count]);
        $this->assertSame(2, $export->files_done);
        $this->assertSame(2, $export->files_total);
        $this->assertSame('no_own_file', $export->skipped[0]['reason']);
        $this->assertCount(1, $export->part_files);
        $this->assertSame('Some_ Album.zip', $export->part_files[0]['name']);
        $this->assertNotNull($export->expires_at);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path($export->directory().'/work'));

        $zip = $this->zip($export);
        $this->assertSame(['The Artist/Some_ Album/01 - Copied.mp3', 'The Artist/Some_ Album/02 - Transcoded.mp3'], $this->names($zip));
        // The copy is byte for byte the original.
        $this->assertSame(file_get_contents($copyPath), $zip->getFromName('The Artist/Some_ Album/01 - Copied.mp3'));

        // The transcode keeps the tags and is MP3.
        $out = $this->library.'/out.mp3';
        file_put_contents($out, $zip->getFromName('The Artist/Some_ Album/02 - Transcoded.mp3'));
        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format_tags=title,artist:stream=codec_name', '-of', 'json', $out]);
        $this->assertStringContainsString('"title": "Tone"', $probe->output());
        $this->assertStringContainsString('mp3', $probe->output());
        $this->assertStringContainsString('Tagged Artist', $probe->output());
    }

    public function test_transcode_embeds_the_cover_as_a_small_baseline_jpeg(): void
    {
        $this->needFfmpeg();
        $album = $this->album();
        $url = 'https://example.test/cover.jpg';
        $album->update(['images' => [$url]]);
        $dir = Storage::disk('public')->path('artwork/'.md5($url));
        @mkdir($dir, 0775, true);
        Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=900x900:rate=1', '-frames:v', '1', "{$dir}/512.jpg"]);
        $this->track($album, 'Song', $this->audio('b', 'flac'), 'flac', 0);

        $export = $this->runExport($this->export('album', $album->id));
        $this->assertSame(Export::DONE, $export->status, (string) $export->error);

        $out = $this->library.'/out.mp3';
        file_put_contents($out, $this->zip($export)->getFromIndex(0));
        $probe = Process::run(['ffprobe', '-v', 'error', '-select_streams', 'v', '-show_entries', 'stream=codec_name,width,height,pix_fmt', '-of', 'csv=p=0', $out]);
        [$codec, $width, $height] = explode(',', trim($probe->output()));
        $this->assertSame('mjpeg', $codec);
        $this->assertLessThanOrEqual(500, (int) $width);
        $this->assertLessThanOrEqual(500, (int) $height);
        $this->assertStringContainsString('420', $probe->output());

        $jpeg = Process::run(['ffmpeg', '-v', 'error', '-i', $out, '-map', '0:v', '-c', 'copy', '-f', 'image2pipe', '-'])->output();
        // SOF0 (baseline) present, SOF2 (progressive) absent.
        $this->assertStringContainsString("\xFF\xC0", $jpeg);
        $this->assertStringNotContainsString("\xFF\xC2", $jpeg);
    }

    public function test_playlist_export_is_flat_with_an_m3u8(): void
    {
        $this->needFfmpeg();
        $album = $this->album();
        $a = $this->track($album, 'Second on album', $this->audio('a', 'mp3'), 'mp3', 128, 2);
        $b = $this->track($album, 'First on album', $this->audio('b', 'mp3'), 'mp3', 128, 1);
        $playlist = Playlist::create(['name' => 'Road trip']);
        $playlist->tracks()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2]]);

        $export = $this->runExport($this->export('playlist', $playlist->id));

        $this->assertSame(Export::DONE, $export->status, (string) $export->error);
        $zip = $this->zip($export);
        $this->assertSame(['001 - The Artist - Second on album.mp3', '002 - The Artist - First on album.mp3', 'playlist.m3u8'], $this->names($zip));
        $m3u = $zip->getFromName('playlist.m3u8');
        $this->assertStringStartsWith("#EXTM3U\r\n", $m3u);
        $this->assertLessThan(strpos($m3u, '002 - '), strpos($m3u, '001 - '));
        $this->assertStringContainsString("#EXTINF:1,The Artist - Second on album\r\n001 - The Artist - Second on album.mp3", $m3u);
    }

    public function test_parts_are_split_by_size(): void
    {
        $this->needFfmpeg();
        config(['export.part_gb' => 0.00001]); // about 10 KB: a one second mp3 is about 16 KB, so one file per part
        $album = $this->album();
        $this->track($album, 'One', $this->audio('a', 'mp3'), 'mp3', 128, 1);
        $this->track($album, 'Two', $this->audio('b', 'mp3'), 'mp3', 128, 2);
        $this->track($album, 'Three', $this->audio('c', 'mp3'), 'mp3', 128, 3);

        $export = $this->runExport($this->export('album', $album->id));

        $this->assertSame(Export::DONE, $export->status, (string) $export->error);
        $this->assertCount(3, $export->part_files);
        $this->assertSame('Some_ Album (part 1 of 3).zip', $export->part_files[0]['name']);
        foreach ([0 => 'One', 1 => 'Two', 2 => 'Three'] as $i => $title) {
            $this->assertCount(1, $this->names($this->zip($export, $i)));
            $this->assertStringContainsString($title, $this->names($this->zip($export, $i))[0]);
        }
        $this->assertSame(array_sum(array_column($export->part_files, 'size')), $export->bytes);
    }

    public function test_a_failing_file_is_skipped_and_the_rest_go_on(): void
    {
        $this->needFfmpeg();
        $album = $this->album();
        $bad = $this->library.'/bad.flac';
        file_put_contents($bad, 'this is not audio');
        $this->track($album, 'Broken', $bad, 'flac', 0, 1);
        $this->track($album, 'Fine', $this->audio('a', 'mp3'), 'mp3', 128, 2);

        $export = $this->runExport($this->export('album', $album->id));

        $this->assertSame(Export::DONE, $export->status);
        $this->assertSame([['track_id' => 1, 'name' => 'Broken', 'reason' => 'transcode_failed']], $export->skipped);
        $this->assertSame(1, $export->skipped_count);
        $this->assertCount(1, $this->names($this->zip($export)));
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path($export->directory().'/work'));
    }

    public function test_when_nothing_can_be_written_the_export_fails_and_the_output_is_removed(): void
    {
        $this->needFfmpeg();
        $album = $this->album();
        $bad = $this->library.'/bad.flac';
        file_put_contents($bad, 'this is not audio');
        $this->track($album, 'Broken', $bad, 'flac', 0);

        $export = $this->runExport($this->export('album', $album->id));

        $this->assertSame(Export::FAILED, $export->status);
        $this->assertNotNull($export->error);
        $this->assertNull($export->part_files);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path($export->directory()));
    }

    public function test_a_cancelled_export_stops_and_removes_its_files(): void
    {
        $this->needFfmpeg();
        $album = $this->album();
        $this->track($album, 'One', $this->audio('a', 'mp3'), 'mp3', 128, 1);
        $export = $this->export('album', $album->id);
        // Removed while queued (what a cancel does): the first status check inside the run sees nothing running.
        $copy = Export::find($export->id);
        $export->delete();

        (new ExportLibrarySelection($copy))->handle();

        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path($copy->directory()));
    }

    public function test_an_export_over_the_limit_is_refused_before_anything_is_written(): void
    {
        $this->needFfmpeg();
        config(['export.max_gb' => 0.000001]); // about 1 KB
        $album = $this->album();
        $this->track($album, 'One', $this->audio('a', 'mp3'), 'mp3', 128);

        $export = $this->runExport($this->export('album', $album->id));

        $this->assertSame(Export::FAILED, $export->status);
        $this->assertStringContainsString('max_gb', $export->error);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path($export->directory()));
    }

    public function test_an_export_that_does_not_fit_on_the_disk_is_refused(): void
    {
        $this->needFfmpeg();
        config(['export.free_margin_mb' => 100_000_000]); // more than any disk has free
        $album = $this->album();
        $this->track($album, 'One', $this->audio('a', 'mp3'), 'mp3', 128);

        $export = $this->runExport($this->export('album', $album->id));

        $this->assertSame(Export::FAILED, $export->status);
        $this->assertStringContainsString('free disk space', $export->error);
    }

    public function test_names_are_safe_for_fat32_and_unique(): void
    {
        $names = new ExportNames;
        $first = $names->path('tree', 'AC/DC', 'Who Made Who?', 'Back in Black.', 'MP3', 1, 4);
        $this->assertSame('AC_DC/Who Made Who/04 - Back in Black.mp3', $first);
        $this->assertSame('AC_DC/Who Made Who/04 - back in black (2).mp3', $names->path('tree', 'AC/DC', 'Who Made Who?', 'back in black', 'mp3', 2, 4));
        $this->assertSame('Unknown Artist/Unknown Album/05 - Untitled.mp3', $names->path('tree', null, null, '  ', 'mp3', 5, 5));
        $this->assertSame('_CON', ExportNames::clean('CON', 'x'));
        $this->assertSame('x', ExportNames::clean('. . .', 'x'));
        $this->assertSame(60, mb_strlen(ExportNames::clean(str_repeat('é', 200), 'x')));
        $this->assertSame('2-01 - Disc two.mp3', basename($names->path('tree', 'A', 'B', 'Disc two', 'mp3', 1, 1, 2)));
        $this->assertSame('007 - A - T.mp3', $names->path('flat', 'A', 'B', 'T', 'mp3', 7));
    }

    public function test_zip_writer_makes_a_valid_store_zip(): void
    {
        $path = sys_get_temp_dir().'/zipwriter-'.uniqid().'.zip';
        $source = $path.'.src';
        file_put_contents($source, str_repeat('abc', 1000));
        $zip = new ZipWriter($path);
        $zip->addFile('dir/ünï.txt', $source);
        $zip->addString('empty.txt', '');
        $zip->close();

        $read = new ZipArchive;
        $this->assertTrue($read->open($path) === true);
        $this->assertSame(2, $read->numFiles);
        $this->assertSame(str_repeat('abc', 1000), $read->getFromName('dir/ünï.txt'));
        $stat = $read->statName('dir/ünï.txt');
        $this->assertSame(0, $stat['comp_method']);
        $this->assertSame(crc32(str_repeat('abc', 1000)), $stat['crc']);
        $this->assertSame(filesize($path), $zip->size());
        $read->close();
        $this->assertSame(0, Process::run(['unzip', '-tq', $path])->exitCode() ?? 0);
        @unlink($path);
        @unlink($source);
    }

    public function test_the_job_runs_on_the_heavy_queue_with_a_lock_per_export(): void
    {
        $album = $this->album();
        $a = $this->export('album', $album->id);
        $b = $this->export('album', $album->id);
        $job = new ExportLibrarySelection($a);

        $this->assertSame('heavy', $job->queue);
        $this->assertGreaterThan(3600, $job->timeout);
        $this->assertNotSame($job->uniqueId(), (new ExportLibrarySelection($b))->uniqueId());
    }
}
