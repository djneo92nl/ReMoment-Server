<?php

namespace App\Domain\Catalogue;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Availability\Providers;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\Normalizer;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use Illuminate\Support\Facades\Log;

/**
 * Songs wished for from the play history, a radio station or by hand: one wish per (artist, title) key, household-wide.
 * A wish closes by itself when the library has the song as an own file or on a DLNA server (the same owned rule as
 * albums); a library track found for it is linked, so the song can be opened. See docs/architecture/availability-layer.md.
 */
final class WantedTracks
{
    public const SOURCES = ['history', 'radio', 'manual', 'streaming'];

    public static function wish(string $artist, string $title, ?string $album = null, ?string $imageUrl = null, string $source = 'manual'): WantedTrack
    {
        $song = WantedTrack::firstOrNew([
            'artist_key' => Normalizer::artist($artist),
            'title_key' => Normalizer::track($title),
        ]);

        if (!$song->exists) {
            $song->fill([
                'artist' => trim($artist),
                'title' => trim($title),
                'source' => in_array($source, self::SOURCES, true) ? $source : 'manual',
                'wanted_at' => now(),
            ]);
        } else {
            // Wishing again keeps the first date, and wishes a closed one anew.
            $song->fulfilled_at = null;
        }

        $song->album ??= $album !== null && trim($album) !== '' ? trim($album) : null;
        $song->image_url ??= $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null;
        $song->save();

        self::refresh($song);

        return $song;
    }

    public static function unwish(WantedTrack $song): void
    {
        $song->delete();
    }

    /** Takes the song of this artist and title off the wishlist, however it was spelled. */
    public static function unwishByName(string $artist, string $title): void
    {
        WantedTrack::where('artist_key', Normalizer::artist($artist))->where('title_key', Normalizer::track($title))->get()->each(fn (WantedTrack $s) => self::unwish($s));
    }

    /** Whether this artist and title is on the wishlist (and not closed). */
    public static function isWished(?string $artist, ?string $title): bool
    {
        return $artist !== null && $title !== null
            && WantedTrack::where('artist_key', Normalizer::artist($artist))->where('title_key', Normalizer::track($title))->whereNull('fulfilled_at')->exists();
    }

    /** Links the library track of the song and sets or clears `fulfilled_at`. */
    public static function refresh(WantedTrack $song): void
    {
        $track = $song->track ?? LibraryIdentity::lookupTrack($song->artist, $song->album, $song->title, null, null, null);
        $track = $track !== null && !$track->isRadioStub() ? $track : null;

        $song->track_id = $track?->id;
        $owned = $track !== null && self::isOwned($track);
        $song->fulfilled_at = $owned ? ($song->fulfilled_at ?? now()) : null;
        $song->save();
    }

    /** For scans and imports, per track: a cheap lookup by key, never an error for the caller. */
    public static function refreshForTrackQuietly(Track $track): void
    {
        try {
            $key = Normalizer::track($track->name);
            foreach (WantedTrack::where('title_key', $key)->whereNull('fulfilled_at')->get() as $song) {
                self::refresh($song);
            }
        } catch (\Throwable $e) {
            Log::warning("Wished song check of track {$track->id} failed: {$e->getMessage()}");
        }
    }

    public static function isOwned(Track $track): bool
    {
        foreach (Providers::ownedLibraries() as $source) {
            if (LibrarySources::availableFrom(Track::query()->whereKey($track->id), $source)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public static function item(WantedTrack $song): array
    {
        return [
            'id' => $song->id,
            'artist' => $song->artist,
            'title' => $song->title,
            'album' => $song->album,
            'track_id' => $song->track_id,
            'fulfilled' => $song->fulfilled_at !== null,
            'source' => $song->source,
            'wanted_at' => $song->wanted_at->toIso8601String(),
            'artwork' => LibraryItemArtwork::forUrl($song->image_url),
        ];
    }

    /** @return list<array<string, mixed>> wished, not yet owned, most recently wished first */
    public static function wishlist(int $limit = 100): array
    {
        return WantedTrack::whereNull('fulfilled_at')->orderByDesc('wanted_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (WantedTrack $s) => self::item($s))->all();
    }
}
