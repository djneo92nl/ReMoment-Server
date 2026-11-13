<?php

namespace Tests\Support;

use App\Models\Media\Album;
use App\Models\Media\Metadata;
use App\Models\Media\Track;

/** Test fixture: a library track playable from DLNA (a stream URL in its metadata) and/or Spotify (a URI as its id). */
trait MakesLibraryTracks
{
    /**
     * @param  bool  $dlna  a stream URL, from `$streamSource`
     * @param  string  $streamSource  `files` (the Local library, the default) or `dlna:{server}` (a DLNA server)
     * @param  int|null  $artistId  defaults to the album's artist
     */
    protected function makeLibraryTrack(?Album $album, string $name, bool $dlna = true, ?string $spotifyId = null, ?int $artistId = null, string $streamSource = 'files'): Track
    {
        $track = Track::create([
            'album_id' => $album?->id,
            'artist_id' => $artistId ?? $album->artist_id,
            'external_id' => $spotifyId ? "spotify:track:{$spotifyId}" : uniqid('local:', true),
            'name' => $name,
            'duration' => 200,
            'source' => $spotifyId ? 'spotify' : explode(':', $streamSource)[0],
        ]);

        if ($dlna) {
            Metadata::create([
                'metadatable_type' => Track::class,
                'metadatable_id' => $track->id,
                'key' => 'dlna_url',
                'value' => "http://nas.test/{$track->id}.flac",
                'type' => 'url',
                'source' => $streamSource,
            ]);
        }

        return $track;
    }

    /**
     * A library track with an own file and an analysis row (the Local library's analysed track).
     *
     * @param  array<string, mixed>  $analysis  FileAnalysis columns (bpm, key, mode, arousal, …)
     */
    protected function makeAnalysedTrack(Album $album, string $name, array $analysis = [], array $file = []): Track
    {
        $track = $this->makeLibraryTrack($album, $name);
        $location = \App\Models\StorageLocation::first() ?? \App\Models\StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $f = \App\Models\File::create($file + ['storage_location_id' => $location->id, 'path' => "{$track->id}-{$name}.flac", 'hash' => "h{$track->id}", 'size' => 1, 'mtime' => 1, 'status' => \App\Models\File::PRESENT, 'extension' => 'flac']);
        \App\Models\TrackFile::create(['track_id' => $track->id, 'file_id' => $f->id, 'role' => \App\Models\TrackFile::ORIGINAL, 'is_primary' => true]);
        \App\Models\FileAnalysis::create($analysis + ['file_id' => $f->id, 'content_hash' => "h{$track->id}"]);

        return $track;
    }
}
