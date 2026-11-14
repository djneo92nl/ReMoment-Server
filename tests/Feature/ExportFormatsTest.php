<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Domain\Export\ExportFormats;
use App\Domain\Export\ExportLoudness;
use App\Domain\Export\ExportPlanner;
use App\Jobs\ExportLibrarySelection;
use App\Models\AdminToken;
use App\Models\Export;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** AAC and Opus profiles and the loudness option of the export (workstream 13, 1.1). */
class ExportFormatsTest extends TestCase
{
    use RefreshDatabase;

    private string $library;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Tools::forget();
        ExportFormats::forget();
        config(['export.nice' => 0]);
        $this->library = sys_get_temp_dir().'/remoment-export-formats-'.uniqid();
        mkdir($this->library, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->library));
        Tools::forget();
        ExportFormats::forget();
        parent::tearDown();
    }

    private function needEncoder(string ...$formats): void
    {
        config(['analysis.ffmpeg' => 'ffmpeg']);
        Tools::forget();
        ExportFormats::forget();
        foreach (['mp3', ...$formats] as $format) {
            if (!ExportFormats::available($format)) {
                $this->markTestSkipped("ffmpeg has no {$format} encoder.");
            }
        }
    }

    private function audio(string $name, string $ext, string $volume = '1.0'): string
    {
        $path = "{$this->library}/{$name}.{$ext}";
        $codec = $ext === 'flac' ? ['-c:a', 'flac'] : ['-c:a', 'libmp3lame', '-b:a', '128k'];
        $r = Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-af', "volume={$volume}", ...$codec, '-metadata', 'title=Tone', '-metadata', 'artist=Tagged Artist', '-metadata', 'replaygain_track_gain=-3.00 dB', $path]);
        $this->assertTrue($r->successful(), $r->errorOutput());

        return $path;
    }

    private function album(): Album
    {
        return Album::create(['artist_id' => Artist::create(['name' => 'The Artist'])->id, 'name' => 'Album']);
    }

    private function track(Album $album, string $name, ?string $path, string $codec, int $kbps = 0, int $duration = 2): Track
    {
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $album->artist_id, 'external_id' => uniqid('local:'), 'name' => $name, 'duration' => $duration, 'source' => 'files']);
        if ($path !== null) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->library], 'role' => 'library']);
            $f = File::create([
                'storage_location_id' => $location->id, 'path' => basename($path), 'hash' => uniqid(), 'size' => is_file($path) ? filesize($path) : 1000, 'mtime' => 1,
                'extension' => pathinfo($path, PATHINFO_EXTENSION), 'codec' => $codec, 'bitrate' => $kbps, 'sample_rate' => 44100, 'channels' => 2,
                'duration' => $duration, 'status' => File::PRESENT,
            ]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $f->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        }

        return $track;
    }

    private function analyse(Track $track, array $values): void
    {
        FileAnalysis::create(['file_id' => TrackFile::where('track_id', $track->id)->value('file_id')] + $values);
    }

    private function run_(string $profile, int $albumId, ?array $options = null): Export
    {
        $export = Export::create(['selection_type' => 'album', 'selection_id' => $albumId, 'profile' => $profile, 'options' => $options, 'status' => Export::QUEUED]);
        (new ExportLibrarySelection($export))->handle();

        return $export->fresh();
    }

    private function entry(Export $export, string $name): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->directory().'/'.$export->part_files[0]['name'])) === true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_contains($zip->getNameIndex($i), $name)) {
                $out = $this->library.'/out-'.basename($zip->getNameIndex($i));
                file_put_contents($out, $zip->getFromIndex($i));

                return $out;
            }
        }
        $this->fail("No {$name} in the zip.");
    }

    private function probe(string $path, string $entries): array
    {
        return json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', $entries, '-of', 'json', $path])->output(), true);
    }

    private function maxVolume(string $path): float
    {
        $r = Process::run(['ffmpeg', '-nostdin', '-i', $path, '-af', 'volumedetect', '-vn', '-f', 'null', '-']);
        preg_match('/max_volume:\s+(-?[\d.]+) dB/', $r->errorOutput(), $m);

        return (float) $m[1];
    }

    // --- planner (no ffmpeg needed) -------------------------------------------------------------------------

    private function file(string $codec, ?int $kbps, int $duration = 100): File
    {
        return new File(['id' => 1, 'codec' => $codec, 'extension' => $codec, 'bitrate' => $kbps, 'sample_rate' => 44100, 'channels' => 2, 'duration' => $duration, 'size' => 5_000_000]);
    }

    public function test_aac_profiles_copy_aac_and_mp3_within_the_ceiling_and_transcode_the_rest(): void
    {
        $planner = new ExportPlanner;

        $this->assertSame('copy', $planner->decide($this->file('aac', 128), 'aac-192')->action);
        $this->assertSame('copy', $planner->decide($this->file('mp3', 192), 'aac-192')->action);

        $above = $planner->decide($this->file('aac', 256), 'aac-192');
        $this->assertSame(['transcode', 'above_ceiling', 'aac', 192], [$above->action, $above->reason, $above->target['codec'], $above->target['bitrate']]);

        $flac = $planner->decide($this->file('flac', null), 'aac-256');
        $this->assertSame('lossless', $flac->reason);
        $this->assertSame((int) round(100 * 256 * 125 * 1.01), $flac->estimatedBytes);
        $this->assertNull($flac->target['gain_db']);
    }

    public function test_opus_profiles_keep_only_opus(): void
    {
        $planner = new ExportPlanner;

        $this->assertSame('copy', $planner->decide($this->file('opus', 96), 'opus-128')->action);
        $mp3 = $planner->decide($this->file('mp3', 320), 'opus-128');
        $this->assertSame(['transcode', 'codec_not_accepted', 'opus', 128], [$mp3->action, $mp3->reason, $mp3->target['codec'], $mp3->target['bitrate']]);
        $this->assertSame('lossless', $planner->decide($this->file('flac', null), 'opus-64')->reason);
        $this->assertSame((int) round(100 * 64 * 125 * 1.02), $planner->decide($this->file('flac', null), 'opus-64')->estimatedBytes);
    }

    public function test_every_profile_target_is_a_known_format_and_ids_are_documented(): void
    {
        foreach (config('export.profiles') as $key => $profile) {
            $this->assertTrue($profile['target'] === null || in_array($profile['target'], ExportFormats::ids(), true), $key);
        }
        $this->assertSame(['m4a', 'opus', 'mp3'], [ExportFormats::extension('aac'), ExportFormats::extension('opus'), ExportFormats::extension('mp3')]);
    }

    // --- loudness (database, no ffmpeg) ---------------------------------------------------------------------

    public function test_track_gain_from_tag_else_from_measured_loudness_and_never_clips(): void
    {
        $album = $this->album();
        $tagged = $this->track($album, 'Tagged', 'a.flac', 'flac');
        $this->analyse($tagged, ['replaygain_track_gain' => -6.5, 'loudness_lufs' => -12.0, 'true_peak' => -1.0]);
        $measured = $this->track($album, 'Quiet', 'b.flac', 'flac');
        $this->analyse($measured, ['loudness_lufs' => -22.0, 'true_peak' => -2.0]); // wants +4, headroom 2 - 0.3 = 1.7
        $roomy = $this->track($album, 'Roomy', 'c.flac', 'flac');
        $this->analyse($roomy, ['loudness_lufs' => -22.0, 'true_peak' => -12.0]); // +4, room for 11.7
        $noPeak = $this->track($album, 'No peak', 'd.flac', 'flac');
        $this->analyse($noPeak, ['loudness_lufs' => -22.0]); // never boosted without a peak
        $unanalysed = $this->track($album, 'Unanalysed', 'e.flac', 'flac');

        $plan = (new ExportPlanner)->plan(Track::orderBy('id')->get(), 'mp3-128', 'track');
        $gain = fn (Track $t) => collect($plan->items)->firstWhere('trackId', $t->id)->target['gain_db'];

        $this->assertSame(-6.5, $gain($tagged));
        $this->assertSame(1.7, $gain($measured));
        $this->assertSame(4.0, $gain($roomy));
        $this->assertSame(0.0, $gain($noPeak));
        $this->assertNull($gain($unanalysed));
        $this->assertSame(['track', 4, 1], [$plan->loudness, $plan->leveled, $plan->unleveled]);
        $this->assertSame(['mode' => 'track', 'leveled' => 4, 'unleveled' => 1], $plan->totals()['loudness']);
    }

    public function test_album_gain_keeps_the_balance_and_is_limited_by_the_loudest_peak(): void
    {
        $album = $this->album();
        $a = $this->track($album, 'A', 'a.flac', 'flac');
        $this->analyse($a, ['loudness_lufs' => -26.0, 'true_peak' => -9.0, 'replaygain_track_gain' => 8.0]);
        $b = $this->track($album, 'B', 'b.flac', 'flac');
        $this->analyse($b, ['loudness_lufs' => -22.0, 'true_peak' => -1.0, 'replaygain_track_gain' => 4.0]);

        $plan = (new ExportPlanner)->plan(Track::orderBy('id')->get(), 'mp3-128', 'album');
        $gains = collect($plan->items)->map(fn ($i) => $i->target['gain_db'])->all();

        // Energy mean of -26 and -22 (equal durations) is about -24.8: album gain +6.8, limited by B's peak (-1 dBFS): +0.7 for both.
        $this->assertSame([0.7, 0.7], $gains);

        // An album tag wins over the computed value.
        FileAnalysis::query()->update(['replaygain_album_gain' => -2.0]);
        $plan = (new ExportPlanner)->plan(Track::orderBy('id')->get(), 'mp3-128', 'album');
        $this->assertSame([-2.0, -2.0], collect($plan->items)->map(fn ($i) => $i->target['gain_db'])->all());
    }

    public function test_copies_never_get_a_gain_and_off_adds_nothing(): void
    {
        $album = $this->album();
        $copy = $this->track($album, 'Copy', 'a.mp3', 'mp3', 128);
        $this->analyse($copy, ['loudness_lufs' => -30.0, 'true_peak' => -20.0]);
        $flac = $this->track($album, 'Flac', 'b.flac', 'flac');
        $this->analyse($flac, ['loudness_lufs' => -30.0, 'true_peak' => -20.0]);

        $plan = (new ExportPlanner)->plan(Track::orderBy('id')->get(), 'mp3-128', 'track');
        $this->assertNull($plan->items[0]->target);
        $this->assertSame('copy', $plan->items[0]->action);
        $this->assertSame([1, 0], [$plan->leveled, $plan->unleveled]);

        $off = (new ExportPlanner)->plan(Track::orderBy('id')->get(), 'mp3-128');
        $this->assertSame('off', $off->loudness);
        $this->assertNull($off->items[1]->target['gain_db']);
        $this->expectException(\InvalidArgumentException::class);
        ExportLoudness::normalise('loud');
    }

    // --- the job with real ffmpeg ---------------------------------------------------------------------------

    private function cover(Album $album): void
    {
        $url = 'https://example.test/cover.jpg';
        $album->update(['images' => [$url]]);
        $dir = Storage::disk('public')->path('artwork/'.md5($url));
        @mkdir($dir, 0775, true);
        Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=900x900:rate=1', '-frames:v', '1', "{$dir}/512.jpg"]);
    }

    public function test_aac_export_makes_m4a_with_tags_and_a_cover(): void
    {
        $this->needEncoder('aac');
        $album = $this->album();
        $this->cover($album);
        $this->track($album, 'Song', $this->audio('a', 'flac'), 'flac');

        $export = $this->run_('aac-128', $album->id);

        $this->assertSame(Export::DONE, $export->status, (string) $export->error);
        $out = $this->entry($export, '.m4a');
        $probe = $this->probe($out, 'format_tags=title,artist:stream=codec_name,codec_type,bit_rate');
        $this->assertSame('aac', $probe['streams'][0]['codec_name']);
        $this->assertSame(['aac', 'mjpeg'], array_column($probe['streams'], 'codec_name'));
        $this->assertSame('Tone', $probe['format']['tags']['title']);
        $this->assertSame('Tagged Artist', $probe['format']['tags']['artist']);
        $video = $this->probe($out, 'stream_disposition=attached_pic:stream=codec_type');
        $this->assertSame(1, collect($video['streams'])->firstWhere('codec_type', 'video')['disposition']['attached_pic']);
    }

    public function test_opus_export_makes_ogg_opus_with_tags_and_a_vorbis_comment_cover(): void
    {
        $this->needEncoder('opus');
        $album = $this->album();
        $this->cover($album);
        $this->track($album, 'Song', $this->audio('a', 'flac'), 'flac');

        $export = $this->run_('opus-96', $album->id);

        $this->assertSame(Export::DONE, $export->status, (string) $export->error);
        $out = $this->entry($export, '.opus');
        $probe = $this->probe($out, 'format_tags:stream_tags:stream=codec_name,sample_rate');
        // ffprobe also lists the picture block as an attached image stream; only one audio stream, and it is Opus.
        $audio = array_values(array_filter($probe['streams'], fn ($s) => isset($s['sample_rate'])));
        $this->assertSame(['opus'], array_column($audio, 'codec_name'));
        $this->assertSame('48000', $audio[0]['sample_rate']);
        $this->assertSame('Tone', $probe['format']['tags']['title'] ?? $audio[0]['tags']['title'] ?? null);

        // The cover is a FLAC picture block in the Vorbis comments (the Ogg pages split it, so read it back through ffmpeg).
        $jpeg = Process::run(['ffmpeg', '-v', 'error', '-i', $out, '-map', '0:v', '-c', 'copy', '-f', 'image2pipe', '-'])->output();
        $this->assertStringStartsWith("\xFF\xD8", $jpeg);
        $this->assertStringContainsString("\xFF\xC0", $jpeg); // baseline
        $size = getimagesizefromstring($jpeg);
        $this->assertLessThanOrEqual(500, $size[0]);
    }

    public function test_loudness_changes_a_transcode_and_removes_the_replaygain_tags_but_not_a_copy(): void
    {
        $this->needEncoder();
        $album = $this->album();
        $copyPath = $this->audio('copy', 'mp3', '0.3');
        $copy = $this->track($album, 'Copy', $copyPath, 'mp3', 128);
        $this->analyse($copy, ['loudness_lufs' => -30.0, 'true_peak' => -20.0]);
        $quiet = $this->audio('quiet', 'flac', '0.25');
        $track = $this->track($album, 'Quiet', $quiet, 'flac');
        $this->analyse($track, ['loudness_lufs' => -24.0, 'true_peak' => -12.0, 'replaygain_track_gain' => null]); // +6 dB

        $plain = $this->run_('mp3-128', $album->id);
        $level = $this->run_('mp3-128', $album->id, ['loudness' => 'track']);

        $this->assertSame(Export::DONE, $level->status, (string) $level->error);
        $this->assertSame(['loudness' => 'track'], $level->options);
        $before = $this->maxVolume($this->entry($plain, 'Quiet'));
        $outPath = $this->entry($level, 'Quiet');
        $this->assertEqualsWithDelta($before + 6.0, $this->maxVolume($outPath), 1.0);
        $tags = $this->probe($outPath, 'format_tags')['format']['tags'] ?? [];
        $this->assertArrayNotHasKey('replaygain_track_gain', array_change_key_case($tags));
        $this->assertArrayHasKey('replaygain_track_gain', array_change_key_case($this->probe($this->entry($plain, 'Quiet'), 'format_tags')['format']['tags']));

        // The copy is bit-exact with and without the option.
        $this->assertSame(file_get_contents($copyPath), file_get_contents($this->entry($level, 'Copy')));
    }

    // --- API --------------------------------------------------------------------------------------------------

    private function adminHeaders(): array
    {
        User::factory()->create();
        [, $token] = AdminToken::issue('test');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_api_validates_the_new_values_and_the_estimate_describes_the_format(): void
    {
        config(['analysis.ffmpeg' => '/bin/echo']);
        Tools::forget();
        Process::fake(['*' => Process::result(output: " A....D libmp3lame           libmp3lame MP3\n A....D aac                  AAC\n")]);
        ExportFormats::forget();
        $album = $this->album();
        $this->track($album, 'Song', 'a.flac', 'flac');
        $h = $this->adminHeaders();

        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id, 'options' => ['loudness' => 'loud']], $h)->assertUnprocessable();
        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id, 'profile' => 'opus-5'], $h)->assertUnprocessable();

        $r = $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id, 'profile' => 'aac-128', 'options' => ['loudness' => 'album']], $h)->assertOk();
        $r->assertJsonPath('format.id', 'aac')->assertJsonPath('format.extension', 'm4a')->assertJsonPath('format.available', true)->assertJsonPath('totals.loudness.mode', 'album');
        $this->assertStringContainsString('older MP3 players', $r->json('format.note'));

        $r = $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id, 'profile' => 'opus-128'], $h)->assertOk();
        $r->assertJsonPath('format.available', false)->assertJsonPath('format.encoder', null);
        $this->assertStringContainsString('many cars and MP3 players do not play Opus', $r->json('format.note'));
        $this->postJson('/api/library/exports/estimate', ['type' => 'album', 'id' => $album->id, 'profile' => 'highest'], $h)->assertJsonPath('format', null);

        // Starting an Opus export needs the encoder when files must be converted.
        $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id, 'profile' => 'opus-128'], $h)->assertUnprocessable()->assertJsonPath('error', 'encoder_unavailable');
        $this->assertSame(0, Export::count());

        $this->postJson('/api/library/exports', ['type' => 'album', 'id' => $album->id, 'profile' => 'aac-128', 'options' => ['loudness' => 'track', 'layout' => 'flat']], $h)->assertStatus(202);
        $this->assertSame(['layout' => 'flat', 'loudness' => 'track'], Export::first()->options);
    }

    public function test_info_lists_the_formats_ffmpeg_can_make(): void
    {
        config(['analysis.ffmpeg' => '/bin/echo']);
        Tools::forget();
        Process::fake(['*' => Process::result(output: " A....D libmp3lame           libmp3lame MP3\n A..X.D opus                 Opus\n A....D libopus              libopus Opus\n")]);
        ExportFormats::forget();

        $this->assertSame(['mp3', 'opus'], ExportFormats::availableIds());
        $this->assertSame('libopus', ExportFormats::encoder('opus'));
        $this->assertFalse(ExportFormats::available('aac'));
        $this->assertTrue(ExportFormats::missing('aac'));
    }
}
