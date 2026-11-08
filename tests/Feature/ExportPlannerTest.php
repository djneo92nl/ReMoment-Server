<?php

namespace Tests\Feature;

use App\Domain\Export\ExportPlanner;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The keep-or-transcode decision of an export (workstream 13, step 1). */
class ExportPlannerTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private function file(string $codec, ?int $kbps, array $extra = []): File
    {
        return new File($extra + ['codec' => $codec, 'bitrate' => $kbps, 'size' => 5_000_000, 'duration' => 200, 'sample_rate' => 44100, 'channels' => 2]);
    }

    private function decide(File $file, string $profile = 'mp3-320')
    {
        return (new ExportPlanner)->decide($file, $profile);
    }

    public function test_default_profile_is_mp3_320(): void
    {
        $this->assertSame('mp3-320', config('export.default_profile'));
        $this->assertSame(ExportPlanner::COPY, (new ExportPlanner)->decide($this->file('mp3', 320))->action);
    }

    public function test_highest_copies_everything(): void
    {
        foreach ([['flac', null], ['mp3', 320], ['vorbis', 500], ['alac', null]] as [$codec, $kbps]) {
            $item = $this->decide($this->file($codec, $kbps), 'highest');
            $this->assertSame(ExportPlanner::COPY, $item->action);
            $this->assertSame(5_000_000, $item->estimatedBytes);
        }
    }

    public function test_lossy_at_or_below_the_ceiling_is_copied(): void
    {
        foreach ([['mp3', 320], ['mp3', 128], ['aac', 256], ['mp3', 320_000]] as [$codec, $kbps]) {
            $item = $this->decide($this->file($codec, $kbps));
            $this->assertSame(ExportPlanner::COPY, $item->action, "$codec $kbps");
            $this->assertSame('within_ceiling', $item->reason);
            $this->assertNull($item->target);
        }
    }

    public function test_lossless_is_transcoded_to_the_ceiling(): void
    {
        foreach (['mp3-320' => 320, 'mp3-256' => 256, 'mp3-192' => 192, 'mp3-128' => 128] as $profile => $rate) {
            foreach (['flac', 'alac', 'wav', 'pcm_s16le'] as $codec) {
                $item = $this->decide($this->file($codec, 900), $profile);
                $this->assertSame(ExportPlanner::TRANSCODE, $item->action);
                $this->assertSame('lossless', $item->reason);
                $this->assertSame(['codec' => 'mp3', 'bitrate' => $rate, 'sample_rate' => null, 'stereo' => false], $item->target);
                $this->assertSame(200 * $rate * 125, $item->estimatedBytes);
            }
        }
    }

    public function test_above_the_ceiling_is_transcoded_and_never_upsampled(): void
    {
        $item = $this->decide($this->file('mp3', 320), 'mp3-192');
        $this->assertSame(['transcode', 'above_ceiling', 192], [$item->action, $item->reason, $item->target['bitrate']]);

        // A 128 kbit/s MP3 stays 128 on every profile: copied, not re-encoded.
        foreach (ExportPlanner::profiles() as $profile) {
            $this->assertSame(ExportPlanner::COPY, $this->decide($this->file('mp3', 128), $profile)->action, $profile);
        }
    }

    public function test_other_lossy_codecs_are_transcoded_below_their_own_bitrate(): void
    {
        $vorbis = $this->decide($this->file('vorbis', 160));
        $this->assertSame(['transcode', 'codec_not_accepted', 160], [$vorbis->action, $vorbis->reason, $vorbis->target['bitrate']]);

        $opus = $this->decide($this->file('opus', 500));
        $this->assertSame(320, $opus->target['bitrate']);
    }

    public function test_unknown_bitrate_is_transcoded_to_the_ceiling(): void
    {
        $item = $this->decide($this->file('mp3', null));
        $this->assertSame(['transcode', 'unknown_bitrate', 320], [$item->action, $item->reason, $item->target['bitrate']]);
    }

    public function test_codec_falls_back_to_the_extension(): void
    {
        $this->assertSame(ExportPlanner::COPY, $this->decide($this->file('', 192, ['extension' => 'mp3']))->action);
        $this->assertSame('lossless', $this->decide($this->file('', null, ['extension' => 'flac']))->reason);
    }

    public function test_compat_keeps_only_mp3_at_44k_stereo(): void
    {
        $this->assertSame(ExportPlanner::COPY, $this->decide($this->file('mp3', 256), 'compat')->action);

        $aac = $this->decide($this->file('aac', 128), 'compat');
        $this->assertSame(['transcode', 'codec_not_accepted', 128], [$aac->action, $aac->reason, $aac->target['bitrate']]);
        $this->assertSame(44100, $aac->target['sample_rate']);
        $this->assertTrue($aac->target['stereo']);

        $this->assertSame('sample_rate', $this->decide($this->file('mp3', 192, ['sample_rate' => 48000]), 'compat')->reason);
        $this->assertSame('channels', $this->decide($this->file('mp3', 192, ['channels' => 6]), 'compat')->reason);
        $this->assertSame('lossless', $this->decide($this->file('flac', null), 'compat')->reason);
        $this->assertSame(320, $this->decide($this->file('flac', null), 'compat')->target['bitrate']);
    }

    public function test_unknown_profile_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->decide($this->file('mp3', 128), 'ogg-q5');
    }

    private function trackWithFile(string $name, ?array $file, ?Album $album = null): Track
    {
        $album ??= Album::first() ?? Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'dlna'])->id, 'name' => 'LP', 'source' => 'dlna']);
        $track = $this->makeLibraryTrack($album, $name);
        if ($file) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
            $f = File::create($file + ['storage_location_id' => $location->id, 'path' => "$name.x", 'hash' => $name, 'mtime' => 1, 'status' => File::PRESENT]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $f->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        }

        return $track;
    }

    public function test_plan_totals_and_skipped_tracks(): void
    {
        $tracks = [
            $this->trackWithFile('a', ['codec' => 'mp3', 'bitrate' => 320000, 'size' => 8_000_000, 'duration' => 200]),
            $this->trackWithFile('b', ['codec' => 'flac', 'size' => 30_000_000, 'duration' => 200]),
            $this->trackWithFile('c', null), // Spotify/DLNA only
            $this->trackWithFile('d', ['codec' => 'mp3', 'bitrate' => 128, 'size' => 3_000_000, 'duration' => 200]),
        ];

        $plan = (new ExportPlanner)->plan($tracks);

        $this->assertSame('mp3-320', $plan->profile);
        $this->assertSame([ExportPlanner::COPY, ExportPlanner::TRANSCODE, ExportPlanner::SKIP, ExportPlanner::COPY], array_map(fn ($i) => $i->action, $plan->items));
        $this->assertSame('no_own_file', $plan->items[2]->reason);
        $this->assertSame($tracks[2]->id, $plan->items[2]->trackId);
        $this->assertSame([4, 2, 1, 1, 3], [$plan->tracks, $plan->copied, $plan->transcoded, $plan->skipped, $plan->exportable()]);
        $this->assertSame(8_000_000 + 8_000_000 + 3_000_000, $plan->estimatedBytes); // flac → 200 s × 320 kbit/s
        $this->assertSame(1, $plan->parts);
        $this->assertFalse($plan->exceedsLimits());
        $this->assertSame(19_000_000, $plan->totals()['estimated_bytes']);
    }

    public function test_highest_profile_estimates_the_original_sizes(): void
    {
        $plan = (new ExportPlanner)->plan([$this->trackWithFile('b', ['codec' => 'flac', 'size' => 30_000_000, 'duration' => 200])], 'highest');
        $this->assertSame([1, 0, 30_000_000], [$plan->copied, $plan->transcoded, $plan->estimatedBytes]);
    }

    public function test_parts_split_at_four_gb_and_limits_are_flagged(): void
    {
        $gb = 1024 ** 3;
        $tracks = [];
        foreach (range(1, 5) as $i) { // five 1.5 GB files, kept as they are: 7.5 GB
            $tracks[] = $this->trackWithFile("t$i", ['codec' => 'flac', 'size' => (int) (1.5 * $gb), 'duration' => 100]);
        }

        $plan = (new ExportPlanner)->plan($tracks, 'highest');
        $this->assertSame(3, $plan->parts); // 2 + 2 + 1 files
        $this->assertFalse($plan->exceedsLimits());

        $tracks[] = $this->trackWithFile('t6', ['codec' => 'flac', 'size' => (int) (1.5 * $gb), 'duration' => 100]); // 9 GB
        $plan = (new ExportPlanner)->plan($tracks, 'highest');
        $this->assertSame(['max_gb'], $plan->exceeds);
        $this->assertSame(3, $plan->parts);

        config(['export.max_gb' => 20, 'export.dir_max_gb' => 8]);
        $this->assertSame(['dir_max_gb'], (new ExportPlanner)->plan($tracks, 'highest')->exceeds);

        config(['export.max_gb' => 20, 'export.dir_max_gb' => 20, 'export.part_gb' => 1]);
        $this->assertSame(6, (new ExportPlanner)->plan($tracks, 'highest')->parts); // each file alone in a part
    }

    public function test_empty_plan(): void
    {
        $plan = (new ExportPlanner)->plan([]);
        $this->assertSame([0, 0, 0], [$plan->tracks, $plan->parts, $plan->estimatedBytes]);
    }
}
