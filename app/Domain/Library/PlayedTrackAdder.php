<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Jobs\ImportSpotifyAlbum;
use App\Models\Media\Track;
use App\Models\Play;
use App\Services\SpotifyTokenService;

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

        $found = LibraryIdentity::lookupTrack($artist, $nowPlaying->album?->name, $name, self::externalId($nowPlaying), $track->source, $track->duration);

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
        $externalId = self::externalId($nowPlaying);
        $stub = LibraryIdentity::lookupTrack($artistName, $nowPlaying->album?->name, $name, $externalId, $source, $npTrack->duration);
        $stub = $stub?->isRadioStub() ? $stub : null;

        $stub?->update(['images' => null]); // the station's logo, so the real cover can take its place

        // Without an id of its own (radio), look the track up on Spotify and put that in the library.
        if ($externalId === null && ($spotifyId = self::findOnSpotify($artistName, $name)) !== null) {
            $track = app(SpotifyLibraryImporter::class)->importTrackById($spotifyId);
            self::replaceStub($stub, $track);
            self::linkPlays($track, $name, $artistName);

            return $track;
        }

        $artist = LibraryIdentity::artist($artistName, $npTrack->artist?->source ?? $source);

        $album = null;
        $albumName = trim((string) $nowPlaying->album?->name);
        if ($albumName !== '') {
            $album = LibraryIdentity::album($artist, $albumName, $nowPlaying->album?->source ?? $source, [
                'images' => self::images($nowPlaying->album?->images ?? []) ?: null,
                'released_at' => $nowPlaying->album?->released_at ?? null,
            ]);
        }

        $track = LibraryIdentity::track($artist, $album, $name, $externalId, $source, [
            'duration' => $npTrack->duration,
            'images' => self::images($npTrack->images ?? []) ?: null,
        ]);

        if ($track->wasRecentlyCreated) {
            Enrichment::queue($track);
        }

        if ($album !== null && ($spotifyId = SpotifyUri::trackId($externalId)) !== null) {
            ImportSpotifyAlbum::dispatch($album, $spotifyId);
        }

        self::linkPlays($track, $name, $artistName);

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

    /** Spotify's id for a track whose artist and name match exactly, null without a connection or a match. */
    private static function findOnSpotify(string $artistName, string $name): ?string
    {
        $spotify = app(SpotifyTokenService::class);

        if (!$spotify->isConnected()) {
            return null;
        }

        try {
            $results = $spotify->makeApiClient()->search(trim($name.' '.$artistName), 'track', ['limit' => 10]);
        } catch (\Throwable) {
            return null;
        }

        foreach ($results['tracks']['items'] ?? [] as $candidate) {
            $sameName = Normalizer::track($candidate['name'] ?? null) === Normalizer::track($name);
            $sameArtist = collect($candidate['artists'] ?? [])->contains(fn ($a) => Normalizer::artist($a['name'] ?? null) === Normalizer::artist($artistName));

            if ($sameName && $sameArtist && !empty($candidate['id'])) {
                return $candidate['id'];
            }
        }

        return null;
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

        return SpotifyUri::fromMeta($track?->meta ?? [], $track?->source);
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
