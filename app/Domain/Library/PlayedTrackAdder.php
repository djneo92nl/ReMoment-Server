<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;
use App\Jobs\ImportSpotifyAlbum;
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

        return LibraryIdentity::lookupTrack($artist, $nowPlaying->album?->name, $name, self::externalId($nowPlaying), $track->source, $track->duration) !== null;
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
        $artist = LibraryIdentity::artist($artistName, $npTrack->artist?->source ?? $source);

        $album = null;
        $albumName = trim((string) $nowPlaying->album?->name);
        if ($albumName !== '') {
            $album = LibraryIdentity::album($artist, $albumName, $nowPlaying->album?->source ?? $source, [
                'images' => self::images($nowPlaying->album?->images ?? []) ?: null,
                'released_at' => $nowPlaying->album?->released_at ?? null,
            ]);
        }

        $externalId = self::externalId($nowPlaying);
        $track = LibraryIdentity::track($artist, $album, $name, $externalId, $source, [
            'duration' => $npTrack->duration,
            'images' => self::images($npTrack->images ?? []) ?: null,
        ]);

        if ($track->wasRecentlyCreated) {
            Enrichment::queue($track);
        }

        if ($album !== null && $externalId !== null && str_starts_with($externalId, 'spotify:track:')) {
            ImportSpotifyAlbum::dispatch($album, substr($externalId, strlen('spotify:track:')));
        }

        self::linkPlays($track, $name, $artistName);

        return $track;
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

    private static function externalId(NowPlaying $nowPlaying): ?string
    {
        $track = $nowPlaying->track;

        if ($track?->id) {
            return $track->id;
        }

        if ($track?->source !== 'spotify') {
            return null;
        }

        // A speaker playing Spotify (B&O ASE) reports the URI only in its meta.
        foreach ($track->meta ?? [] as $key => $entry) {
            $value = is_array($entry) ? ($entry['spotifyId'] ?? null) : ($key === 'spotifyId' ? $entry : null);

            if (is_string($value) && str_starts_with($value, 'spotify:track:')) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<array{url: string}> */
    private static function images(array $images): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (is_string($image) && trim($image) !== '') {
                $normalized[] = ['url' => trim($image)];
            } elseif (is_array($image) && !empty($image['url'])) {
                $normalized[] = $image;
            }
        }

        return $normalized;
    }
}
