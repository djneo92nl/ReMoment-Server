<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Domain\Storage\PlaybackLoudness;
use App\Domain\Storage\Proxies;
use App\Integrations\Upnp\StreamProfile;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Loudness-normalised playback: a gain only in proxies the server makes anyway, off by default, never past the true peak. */
class PlaybackLoudnessTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Tools::forget();
        config(['proxies.loudness' => ['reference_lufs' => -18.0, 'margin_db' => 1.0, 'max_gain_db' => 9.0]]);
    }

    /** @return array{0: File, 1: Track} */
    private function file(array $analysis = [], string $extension = 'flac', array $file = []): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $album = Album::first() ?? Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'files'])->id, 'name' => 'LP', 'source' => 'files']);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $album->artist_id, 'name' => 'Song '.++$this->n, 'source' => 'files']);
        $f = File::create($file + [
            'storage_location_id' => $location->id, 'path' => "song{$this->n}.{$extension}", 'hash' => 'h'.str_repeat((string) $this->n, 31), 'size' => 1000, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => $extension, 'bit_depth' => 24, 'sample_rate' => 96000,
        ]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $f->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        $analysis !== [] && FileAnalysis::create(['file_id' => $f->id, 'content_hash' => $f->hash] + $analysis);

        return [$f, $track];
    }

    public function test_it_is_off_by_default_and_changes_nothing(): void
    {
        [$file] = $this->file(['loudness_lufs' => -10.0, 'true_peak' => -3.0]);

        $this->assertFalse(PlaybackLoudness::enabled());
        $this->assertNull(PlaybackLoudness::gainFor($file, 'flac-16'));
        $this->assertStringEndsWith('h'.str_repeat('1', 31).'.flac', Proxies::path($file, 'flac-16'));
    }

    public function test_a_loud_track_is_cut_to_the_reference(): void
    {
        PlaybackLoudness::set(true);
        [$file] = $this->file(['loudness_lufs' => -10.0, 'true_peak' => -0.5]);

        $this->assertSame(-8.0, PlaybackLoudness::gainFor($file, 'flac-16'));
        $this->assertStringEndsWith('_-8.0dB.flac', Proxies::path($file, 'flac-16'));
    }

    public function test_a_boost_never_passes_the_true_peak_less_the_margin(): void
    {
        PlaybackLoudness::set(true);
        // Wants +8 dB (-26 to -18), but the peak at -3 dBFS leaves 3 dB, minus the 1 dB margin = 2 dB.
        [$quiet] = $this->file(['loudness_lufs' => -26.0, 'true_peak' => -3.0]);
        // Peak right at the ceiling: no boost at all.
        [$ceiling] = $this->file(['loudness_lufs' => -26.0, 'true_peak' => -0.2]);
        // No measured peak: nothing can be promised, so never boosted.
        [$nopeak] = $this->file(['loudness_lufs' => -26.0]);
        // Wants +12, capped at the configured 9 dB (peak is far away).
        [$verylow] = $this->file(['loudness_lufs' => -30.0, 'true_peak' => -20.0]);

        $this->assertSame(2.0, PlaybackLoudness::gainFor($quiet, 'mp3-high'));
        $this->assertNull(PlaybackLoudness::gainFor($ceiling, 'mp3-high'));
        $this->assertNull(PlaybackLoudness::gainFor($nopeak, 'mp3-high'));
        $this->assertSame(9.0, PlaybackLoudness::gainFor($verylow, 'mp3-high'));
    }

    public function test_the_replaygain_tag_wins_and_unanalysed_files_get_nothing(): void
    {
        PlaybackLoudness::set(true);
        [$tagged] = $this->file(['replaygain_track_gain' => -4.3, 'loudness_lufs' => -10.0, 'true_peak' => -1.0]);
        [$none] = $this->file();
        [$tiny] = $this->file(['loudness_lufs' => -18.04, 'true_peak' => -1.0]);

        $this->assertSame(-4.3, PlaybackLoudness::gainFor($tagged, 'flac-16'));
        $this->assertNull(PlaybackLoudness::gainFor($none, 'flac-16'));
        $this->assertNull(PlaybackLoudness::gainFor($tiny, 'flac-16'));
    }

    public function test_developer_ladder_rungs_are_never_levelled(): void
    {
        PlaybackLoudness::set(true);
        [$file] = $this->file(['loudness_lufs' => -10.0, 'true_peak' => -0.5]);

        $this->assertNull(PlaybackLoudness::gainFor($file, 'mp3-320'));
        $this->assertStringEndsWith('.mp3', Proxies::path($file, 'mp3-320'));
    }

    public function test_the_proxy_is_made_with_the_gain_in_the_same_ffmpeg_run(): void
    {
        config(['analysis.ffmpeg' => 'ffmpeg']);
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = (array) $process->command;
            $commands[] = $command;
            if (is_string(end($command)) && str_ends_with(end($command), '.flac')) {
                file_put_contents(end($command), 'AUDIO');
            }

            return Process::result('ok');
        });
        $dir = sys_get_temp_dir().'/remoment-loudness-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/song.flac", 'x');
        $location = StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $dir], 'role' => 'library']);
        [$file] = $this->file(['loudness_lufs' => -10.0, 'true_peak' => -0.5], 'flac', ['storage_location_id' => $location->id, 'path' => 'song.flac']);

        PlaybackLoudness::set(true);
        $target = Proxies::generate($file->fresh(), 'flac-16');

        $ffmpeg = collect($commands)->first(fn ($c) => in_array('-af', $c, true));
        $this->assertNotNull($ffmpeg);
        $this->assertSame('volume=-8.0dB', $ffmpeg[array_search('-af', $ffmpeg, true) + 1]);
        $this->assertStringEndsWith('_-8.0dB.flac', $target);
        $this->assertTrue(Proxies::disk()->exists($target));

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_a_renderer_without_limits_still_gets_the_untouched_original(): void
    {
        PlaybackLoudness::set(true);
        config(['proxies.enabled' => true, 'analysis.ffmpeg' => 'ffmpeg']);
        [, $track] = $this->file(['loudness_lufs' => -10.0, 'true_peak' => -0.5]);
        $device = Device::factory()->create();

        $this->assertNull(StreamProfile::for($device, $track));
    }

    public function test_the_admin_toggle(): void
    {
        \App\Models\User::factory()->create();
        [, $token] = AdminToken::issue('test');
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/admin/storage/playback-loudness')->assertStatus(401);
        $this->getJson('/api/admin/storage/playback-loudness', $headers)->assertOk()->assertExactJson(['enabled' => false]);
        $this->putJson('/api/admin/storage/playback-loudness', ['enabled' => 'nope'], $headers)->assertStatus(422);
        $this->putJson('/api/admin/storage/playback-loudness', ['enabled' => true], $headers)->assertOk()->assertExactJson(['enabled' => true]);
        $this->assertTrue(PlaybackLoudness::enabled());
        $this->putJson('/api/admin/storage/playback-loudness', ['enabled' => false], $headers)->assertExactJson(['enabled' => false]);

        config(['profile.current' => 'control']);
        $this->getJson('/api/admin/storage/playback-loudness', $headers)->assertStatus(422);
    }
}
