<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\SmartPlaylist\SmartFields;
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

/** Smart playlist rules on what the server computed from the own audio files. */
class SmartAnalysisFieldsTest extends TestCase
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

    public function test_numeric_fields_compare_the_analysis_of_the_primary_file(): void
    {
        $this->track('Slow', ['bpm' => 80.0, 'danceability' => 0.2, 'arousal' => 0.3, 'loudness_lufs' => -18.0]);
        $this->track('Fast', ['bpm' => 140.0, 'danceability' => 0.9, 'arousal' => 0.8, 'loudness_lufs' => -8.0]);
        $this->track('Unanalysed', null);

        $this->assertSame(['Fast'], $this->names([['field' => 'bpm', 'operator' => 'gte', 'value' => 120]]));
        $this->assertSame(['Slow'], $this->names([['field' => 'bpm', 'operator' => 'lte', 'value' => 100]]));
        $this->assertSame(['Fast'], $this->names([['field' => 'danceability', 'operator' => 'gte', 'value' => 50]]));
        $this->assertSame(['Slow'], $this->names([['field' => 'energy', 'operator' => 'lte', 'value' => 40]]));
    }

    public function test_loudness_takes_negative_numbers(): void
    {
        $this->track('Quiet', ['loudness_lufs' => -18.0]);
        $this->track('Loud', ['loudness_lufs' => -8.0]);

        $this->assertSame(['Quiet'], $this->names([['field' => 'loudness', 'operator' => 'lte', 'value' => -14]]));
        $this->assertSame(['Loud'], $this->names([['field' => 'loudness', 'operator' => 'gte', 'value' => -14]]));

        $field = SmartFields::find('loudness');
        $this->assertSame(-14, $field->cleanValue('-14'));
        $this->assertNull(SmartFields::find('bpm')->cleanValue('-5'), 'other number fields stay non-negative');
    }

    public function test_key_mode_mood_and_instrumental(): void
    {
        $this->track('AMinor', ['key' => 'A', 'mode' => 'minor', 'mood_tags' => [['name' => 'sad', 'score' => 0.8]], 'instrumental' => 0.9]);
        $this->track('CMajor', ['key' => 'C', 'mode' => 'major', 'mood_tags' => [['name' => 'happy', 'score' => 0.9]], 'instrumental' => 0.1]);
        $this->track('Unanalysed', null);

        $this->assertSame(['AMinor'], $this->names([['field' => 'musical_key', 'operator' => 'is', 'value' => 'A']]));
        $this->assertSame(['CMajor', 'Unanalysed'], $this->names([['field' => 'musical_key', 'operator' => 'is_not', 'value' => 'A']]), 'is not matches what has no analysis too');
        $this->assertSame(['CMajor'], $this->names([['field' => 'mode', 'operator' => 'is', 'value' => 'major']]));
        $this->assertSame(['CMajor'], $this->names([['field' => 'mood', 'operator' => 'is', 'value' => 'happy']]));
        $this->assertSame(['AMinor'], $this->names([['field' => 'instrumental', 'operator' => 'yes', 'value' => '']]));
    }

    public function test_only_the_primary_file_counts(): void
    {
        $track = $this->track('Two', ['bpm' => 100.0]);
        $location = StorageLocation::first();
        $other = File::create(['storage_location_id' => $location->id, 'path' => 'Two-alt.flac', 'hash' => 'alt', 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $other->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => false]);
        FileAnalysis::create(['file_id' => $other->id, 'content_hash' => 'alt', 'bpm' => 170.0]);

        $this->assertSame([], $this->names([['field' => 'bpm', 'operator' => 'gte', 'value' => 150]]));
        $this->assertSame(['Two'], $this->names([['field' => 'bpm', 'operator' => 'lte', 'value' => 110]]));
    }

    public function test_the_editor_definitions_carry_options_and_the_signed_flag(): void
    {
        $definitions = SmartFields::definitions();

        $this->assertTrue($definitions['loudness']['signed']);
        $this->assertFalse($definitions['bpm']['signed']);
        $this->assertArrayHasKey('A#', $definitions['musical_key']['options']);
        $this->assertArrayHasKey('relaxed', $definitions['mood']['options']);
    }
}
