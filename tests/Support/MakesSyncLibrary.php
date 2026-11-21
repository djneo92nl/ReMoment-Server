<?php

namespace Tests\Support;

use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Support\Facades\Process;

/** A temp library of synthetic files (random bytes, or a real tone through ffmpeg) for the sync tests; no real devices or disks. */
trait MakesSyncLibrary
{
    private string $library;

    protected function makeLibrary(): void
    {
        $this->library = sys_get_temp_dir().'/remoment-sync-test-'.uniqid();
        mkdir($this->library, 0775, true);
    }

    protected function removeLibrary(): void
    {
        exec('rm -rf '.escapeshellarg($this->library));
    }

    protected function album(string $name = 'LP', string $artist = 'Band'): Album
    {
        return Album::create(['artist_id' => Artist::firstOrCreate(['name' => $artist])->id, 'name' => $name]);
    }

    /** A track with a file of random bytes (a copy needs no real audio), or with $real a one second tone in that container. */
    protected function track(Album $album, string $name, ?string $codec = 'mp3', int $kbps = 128, int $size = 4000, int $number = 1, bool $real = false): Track
    {
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $album->artist_id, 'external_id' => uniqid('local:'), 'name' => $name, 'duration' => 200, 'source' => 'files']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'track_number', 'value' => (string) $number, 'type' => 'int', 'source' => 'files']);

        if ($codec) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->library], 'role' => 'library']);
            $path = uniqid('f').'.'.$codec;
            if ($real) {
                $args = $codec === 'flac' ? ['-c:a', 'flac'] : ['-c:a', 'libmp3lame', '-b:a', $kbps.'k'];
                $r = Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', ...$args, '-metadata', "title={$name}", "{$this->library}/{$path}"]);
                $this->assertTrue($r->successful(), $r->errorOutput());
            } else {
                file_put_contents("{$this->library}/{$path}", random_bytes($size));
            }
            $file = File::create([
                'storage_location_id' => $location->id, 'path' => $path, 'hash' => hash_file('sha256', "{$this->library}/{$path}"), 'size' => filesize("{$this->library}/{$path}"),
                'mtime' => 1, 'extension' => $codec, 'codec' => $codec, 'bitrate' => $kbps, 'sample_rate' => 44100, 'channels' => 2, 'duration' => $real ? 1 : 200, 'status' => File::PRESENT,
            ]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        }

        return $track;
    }

    /** What a target would hold after applying a plan: the `have` of the next plan request. @param array<string, mixed> $plan */
    protected function applied(array $plan, array $have = []): array
    {
        $byKey = [];
        foreach ($have as $h) {
            $byKey[$h['key']] = $h;
        }
        foreach ([...$plan['add'], ...$plan['replace']] as $e) {
            $byKey[$e['key']] = ['key' => $e['key'], 'fingerprint' => $e['fingerprint'], 'path' => $e['path'], 'size' => $e['size']];
        }
        foreach ($plan['move'] as $m) {
            $byKey[$m['key']]['path'] = $m['path'];
        }
        foreach ($plan['remove'] as $r) {
            unset($byKey[$r['key']]);
        }

        return array_values($byKey);
    }
}
