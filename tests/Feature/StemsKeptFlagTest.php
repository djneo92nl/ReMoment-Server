<?php

namespace Tests\Feature;

use App\Domain\Analysis\DeepAnalysis;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `has_stems` on the mixer picker's rows (search and mixes-with): one query says which tracks have their stems kept. */
class StemsKeptFlagTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $name, ?string $analysisHash, bool $withStems = true): Track
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => 'hash-'.$name, 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $name, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        if ($analysisHash !== null) {
            FileAnalysis::create([
                'file_id' => $file->id, 'content_hash' => $analysisHash, 'bpm' => 120, 'key' => 'A', 'mode' => 'minor',
                'stem_audio' => $withStems ? ['format' => 'mp3', 'files' => ['vocals' => ['name' => 'vocals.mp3', 'bytes' => 1]]] : null,
            ]);
        }

        return $track;
    }

    public function test_only_tracks_with_current_kept_stems_are_flagged(): void
    {
        $kept = $this->track('Kept', 'hash-Kept');
        $stale = $this->track('Stale', 'old-hash'); // the file changed since
        $none = $this->track('None', 'hash-None', withStems: false);
        $unanalysed = $this->track('Raw', null);

        $this->assertSame([$kept->id => true], DeepAnalysis::stemsKept([$kept->id, $stale->id, $none->id, $unanalysed->id]));
        $this->assertSame([], DeepAnalysis::stemsKept([]));
    }

    public function test_search_rows_carry_the_flag(): void
    {
        $this->track('Song kept', 'hash-Song kept');
        $this->track('Song plain', null);

        $rows = collect($this->getJson('/api/library/search?q=Song')->assertOk()->json('tracks'))->keyBy('name');

        $this->assertTrue($rows['Song kept']['has_stems']);
        $this->assertFalse($rows['Song plain']['has_stems']);
    }
}
