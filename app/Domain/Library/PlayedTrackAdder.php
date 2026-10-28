<?php

namespace App\Domain\Library;

use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Models\Media\Track;
use App\Models\Play;

/**
 * Puts a track that is playing, or was played and only logged as text, into the library
 * on request (the "Add to library" button), whatever the `library_add_played_tracks` flag says.
 */
class PlayedTrackAdder
{
    /** Whether the playing track is already in the library. */
    public static function inLibrary(NowPlaying $nowPlaying): bool
    {
        $track = $nowPlaying->track;
        $artist = trim((string) $track?->artist?->name);
        $name = trim((string) $track?->name);

        if ($artist === '' || $name === '') {
            return true; // nothing to add
        }

        $found = LibraryIdentity::lookupTrack($artist, $nowPlaying->album?->name, $name, NowPlayingTrackResolver::externalId($nowPlaying), $track->source, $track->duration);

        // A radio stub (the stream's own announcement) isn't a library track yet.
        return $found !== null && !$found->isRadioStub();
    }

    public static function add(NowPlaying $nowPlaying): ?Track
    {
        $npTrack = $nowPlaying->track;
        $artistName = trim((string) $npTrack?->artist?->name);
        $name = trim((string) $npTrack?->name);

        if ($artistName === '' || $name === '') {
            return null;
        }

        $source = $npTrack->source;
        $externalId = NowPlayingTrackResolver::externalId($nowPlaying);
        $stub = LibraryIdentity::lookupTrack($artistName, $nowPlaying->album?->name, $name, $externalId, $source, $npTrack->duration);
        $stub = $stub?->isRadioStub() ? $stub : null;

        $stub?->update(['images' => null]); // the station's logo, so the real cover can take its place

        [$track] = NowPlayingTrackResolver::resolve($nowPlaying);

        self::linkPlays($track, $name, $artistName);

        return $track;
    }

    /**
     * The library track of a play from the history: the track itself when it is a real one, else the play's artist and
     * name put in the library like "Add to library" does (a radio stub is replaced, a text-only play is linked).
     */
    public static function addFromPlay(Play $play): ?Track
    {
        $play->loadMissing('track');

        if ($play->track !== null && !$play->track->isRadioStub()) {
            return $play->track;
        }

        $artist = trim((string) ($play->track?->artist?->name ?? $play->artist_name));
        $name = trim((string) ($play->track?->name ?? $play->track_name));
        if ($artist === '' || $name === '') {
            return null;
        }

        $track = self::add(new NowPlaying(
            track: new TrackData(
                name: $name,
                source: $play->source_type === 'spotify' ? 'spotify' : null,
                artist: new ArtistData(name: $artist),
                duration: $play->duration,
                images: $play->image_url ? [$play->image_url] : [],
            ),
            album: ($album = trim((string) ($play->track?->album?->name ?? $play->album_name))) !== '' ? new AlbumData(name: $album, images: $play->image_url ? [$play->image_url] : []) : null,
        ));

        // Nothing else (Spotify) took the stub's place: the station's announcement becomes a library track itself.
        if ($track !== null && $track->isRadioStub()) {
            $track->update(['source' => null]);
        }

        $play->refresh();

        return $track;
    }

    /** Moves everything on a radio stub (its plays, metadata) to the real track and deletes the stub. */
    public static function replaceStub(?Track $stub, Track $track): void
    {
        if ($stub === null || $stub->id === $track->id) {
            return;
        }

        $stub->update(['images' => null]);
        app(LibraryMerger::class)->mergeTracks($track->id, [$stub->id]);
    }

    /** Points the plays that were logged as text for this track at the library track. */
    public static function linkPlays(Track $track, string $trackName, string $artistName): int
    {
        return Play::query()
            ->whereNull('track_id')
            ->where('track_name', $trackName)
            ->where('artist_name', $artistName)
            ->update(['track_id' => $track->id]);
    }
}
