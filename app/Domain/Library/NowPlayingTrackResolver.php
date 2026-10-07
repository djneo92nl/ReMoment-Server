<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;
use App\Jobs\ImportSpotifyAlbum;
use App\Models\Media\Album;
use App\Models\Media\Track;

/**
 * Finds or creates the library artist, album and track of a now-playing track, the one way
 * both a play (StorePlaybackHistory) and "Add to library" (PlayedTrackAdder) put it there.
 */
final class NowPlayingTrackResolver
{
    /**
     * @param  string|null  $fallbackSource  source for a track and album that report none (the event's)
     * @param  Track|null  $matched  a library track already found for it: its artist and album are kept
     * @return array{0: Track, 1: Album|null}
     */
    public static function resolve(NowPlaying $nowPlaying, ?string $fallbackSource = null, ?Track $matched = null): array
    {
        $npTrack = $nowPlaying->track;
        $trackSource = $npTrack->source ?? $fallbackSource;

        $artist = $matched?->artist
            ?? LibraryIdentity::artist(trim((string) $npTrack->artist?->name), $npTrack->artist?->source ?? $npTrack->source);

        $album = null;
        $albumName = trim((string) $nowPlaying->album?->name);

        if ($matched !== null) {
            $album = $matched->album;
        } elseif ($albumName !== '') {
            $album = LibraryIdentity::album($artist, $albumName, $nowPlaying->album?->source ?? $npTrack->source, [
                'images' => self::images($nowPlaying->album?->images ?? []) ?: null,
                'released_at' => $nowPlaying->album?->released_at ?? null,
            ]);
        }

        $externalId = self::externalId($nowPlaying);

        $track = LibraryIdentity::track($artist, $album, trim((string) $npTrack->name), $externalId, $trackSource, [
            'duration' => $npTrack->duration,
            'images' => self::images($npTrack->images ?? []) ?: null,
        ]);

        if ($track->wasRecentlyCreated) {
            Enrichment::queue($track);
        }

        // Add the rest of the album to the library.
        if ($album !== null && ($spotifyId = SpotifyUri::trackId($track->external_id) ?? SpotifyUri::trackId($externalId)) !== null) {
            ImportSpotifyAlbum::dispatch($album, $spotifyId);
        }

        return [$track, $album];
    }

    /** The id a listener reports for the track, else (a speaker playing Spotify) the URI in its meta. */
    public static function externalId(NowPlaying $nowPlaying): ?string
    {
        $track = $nowPlaying->track;

        return $track?->id ?: SpotifyUri::fromMeta($track?->meta ?? [], $track?->source);
    }

    /**
     * Listeners report images as plain URL strings, the library stores (and the views read)
     * them as `{url}` entries like the Spotify importer does.
     *
     * @param  array<int, mixed>  $images
     * @return array<int, array<string, mixed>>
     */
    public static function images(array $images): array
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
