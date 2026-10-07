<?php

namespace App\Domain\Library;

/** Spotify URIs (`spotify:track:<id>`, `spotify:album:<id>`, `spotify:playlist:<id>`) as stored in `external_id` and metadata. */
final class SpotifyUri
{
    private const TRACK = 'spotify:track:';

    private const ALBUM = 'spotify:album:';

    private const PLAYLIST = 'spotify:playlist:';

    public static function track(string $id): string
    {
        return self::TRACK.$id;
    }

    public static function playlist(string $id): string
    {
        return self::PLAYLIST.$id;
    }

    public static function isTrack(mixed $value): bool
    {
        return self::idAfter($value, self::TRACK) !== null;
    }

    public static function isPlaylist(mixed $value): bool
    {
        return self::idAfter($value, self::PLAYLIST) !== null;
    }

    public static function isAlbum(mixed $value): bool
    {
        return self::idAfter($value, self::ALBUM) !== null;
    }

    /** The id of a track URI, null for anything else. */
    public static function trackId(mixed $value): ?string
    {
        return self::idAfter($value, self::TRACK);
    }

    /** The id of an album URI, null for anything else. */
    public static function albumId(mixed $value): ?string
    {
        return self::idAfter($value, self::ALBUM);
    }

    /**
     * A speaker playing Spotify (B&O ASE) reports the track URI only in the now-playing meta
     * (`spotifyId`); null for any other source.
     */
    public static function fromMeta(array $meta, ?string $source): ?string
    {
        if ($source !== 'spotify') {
            return null;
        }

        foreach ($meta as $key => $entry) {
            $value = is_array($entry) ? ($entry['spotifyId'] ?? null) : ($key === 'spotifyId' ? $entry : null);

            if (self::isTrack($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function idAfter(mixed $value, string $prefix): ?string
    {
        if (!is_string($value) || !str_starts_with($value, $prefix) || strlen($value) === strlen($prefix)) {
            return null;
        }

        return substr($value, strlen($prefix));
    }
}
