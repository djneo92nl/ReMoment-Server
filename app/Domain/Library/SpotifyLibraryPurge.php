<?php

namespace App\Domain\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Model;

/**
 * Removes what an earlier Spotify import left in the library (the library is the local tracks; Spotify extends it live).
 * Tracks that another source also has (a DLNA stream, an own file) stay, as do plays: a removed track's plays turn into text
 * plays (LibraryRemover). Spotify playlists, and the albums and artists left empty, go. Idempotent.
 */
final class SpotifyLibraryPurge
{
    /** @return array{tracks: int, playlists: int, albums: int, artists: int} what was (or with $dryRun would be) removed */
    public static function run(bool $dryRun = false): array
    {
        $tracks = Track::query()->where('tracks.source', 'spotify');
        $playlists = Playlist::query()->where('source', 'spotify');
        $result = ['tracks' => 0, 'playlists' => 0, 'albums' => 0, 'artists' => 0];

        if ($dryRun) {
            return ['tracks' => (clone $tracks)->count(), 'playlists' => $playlists->count(), 'albums' => 0, 'artists' => 0];
        }

        (clone $tracks)->chunkById(500, function ($chunk) use (&$result) {
            foreach ($chunk as $track) {
                $result['tracks'] += LibraryRemover::track($track) ? 1 : 0;
            }
        });

        foreach ($playlists->get() as $playlist) {
            $playlist->tracks()->detach();
            $playlist->metadata()->delete();
            $playlist->delete();
            $result['playlists']++;
        }

        // Albums and artists that never had tracks of their own (the remover only cleans up after a track).
        foreach (Album::query()->where('source', 'spotify')->whereDoesntHave('tracks')->get() as $album) {
            self::forget($album);
            $result['albums']++;
        }
        foreach (Artist::query()->where('source', 'spotify')->whereDoesntHave('tracks')->whereDoesntHave('albums')->get() as $artist) {
            self::forget($artist);
            $result['artists']++;
        }

        return $result;
    }

    private static function forget(Model $model): void
    {
        $model->metadata()->delete();
        $model->genreRelation()->detach();
        $model->delete();
    }
}
