<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Smart playlist rules on what the quality stage measured, and how it reaches the track details. */
class SmartQualityFieldsTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        $this->album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'dlna'])->id, 'name' => 'LP', 'source' => 'dlna']);
    }

    private function track(string $name, ?array $analysis): Track
    {
        $track = $this->makeLibraryTrack($this->album, $name);

        if ($analysis !== null) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
            $file = File::create(['storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $name, 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
            FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $name] + $analysis);
        }

        return $track;
    }

    /** @return list<string> */
    private function names(array $rules): array
    {
        return SmartPlaylistBuilder::tracks(['match' => 'all', 'rules' => $rules, 'sort' => 'name'])->pluck('tracks.name')->all();
    }

    public function test_transcode_clipping_and_cutoff_fields(): void
    {
        $this->track('Fake', ['lossless_suspect' => 0.95, 'spectral_cutoff_hz' => 16500, 'clipping_suspect' => false]);
        $this->track('Clipped', ['lossless_suspect' => 0.0, 'spectral_cutoff_hz' => null, 'clipping_suspect' => true]);
        $this->track('Clean', ['lossless_suspect' => 0.0, 'spectral_cutoff_hz' => null, 'clipping_suspect' => false]);
        $this->track('Unanalysed', null);

        $this->assertSame(['Fake'], $this->names([['field' => 'transcode', 'operator' => 'yes', 'value' => '']]));
        $this->assertEqualsCanonicalizing(['Clipped', 'Clean', 'Unanalysed'], $this->names([['field' => 'transcode', 'operator' => 'no', 'value' => '']]));
        $this->assertSame(['Clipped'], $this->names([['field' => 'clipping', 'operator' => 'yes', 'value' => '']]));
        $this->assertSame(['Fake'], $this->names([['field' => 'cutoff', 'operator' => 'lte', 'value' => 18]]));
        $this->assertSame([], $this->names([['field' => 'cutoff', 'operator' => 'gte', 'value' => 20]]));
    }

    public function test_track_details_carry_the_quality_results(): void
    {
        $track = $this->track('Fake', [
            'lossless_suspect' => 0.954, 'spectral_cutoff_hz' => 16500, 'measured_bit_depth' => 16, 'clipping_suspect' => false,
            'is_mono' => false, 'phase_issue' => true, 'lead_silence_ms' => 1200, 'trail_silence_ms' => 300, 'decode_warnings' => ['Header missing'],
        ]);

        $sound = $track->sound();

        $this->assertSame(16500, $sound['spectral_cutoff_hz']);
        $this->assertSame(0.95, $sound['lossless_suspect']);
        $this->assertSame(16, $sound['measured_bit_depth']);
        $this->assertFalse($sound['clipping']);
        $this->assertTrue($sound['phase_issue']);
        $this->assertSame(['Header missing'], $sound['decode_warnings']);

        $this->get("/albums/{$track->album_id}")->assertOk()
            ->assertSee('Suspected transcode, spectrum ends at 16.5 kHz')
            ->assertSee('Channels out of phase')
            ->assertSee('Decoder warnings: Header missing');
    }

    public function test_a_track_without_the_stage_has_null_quality_fields(): void
    {
        $track = $this->track('Old', ['loudness_lufs' => -10.0]);

        $sound = $track->sound();

        $this->assertNull($sound['spectral_cutoff_hz']);
        $this->assertNull($sound['lossless_suspect']);
        $this->assertSame([], $sound['decode_warnings']);
    }
}
