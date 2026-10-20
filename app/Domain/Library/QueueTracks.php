<?php

namespace App\Domain\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What a client can put in a queue (a track, album, artist or playlist) and the tracks that makes, in play order. */
final class QueueTracks
{
    /** Item type => its table. */
    public const TYPES = [
        'track' => 'tracks',
        'album' => 'albums',
        'artist' => 'artists',
        'playlist' => 'playlists',
    ];

    public static function exists(?string $type, int $id): bool
    {
        $table = self::TYPES[$type] ?? null;

        return $table !== null && DB::table($table)->where('id', $id)->exists();
    }

    /** @return Collection<int, Track> */
    public static function for(string $type, int $id): Collection
    {
        return match ($type) {
            'track' => Track::with('metadata', 'artist', 'album')->whereKey($id)->get(),
            'album' => LibraryPlayback::albumTracks(Album::findOrFail($id)),
            'artist' => LibraryPlayback::artistTracks(Artist::findOrFail($id)),
            'playlist' => LibraryPlayback::playlistTracks(Playlist::findOrFail($id)),
        };
    }
}
