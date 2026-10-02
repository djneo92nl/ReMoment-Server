<?php

namespace App\Domain\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * One record per artist, album and track, whichever source names it. Every
 * path that adds to the library (DLNA scan, Spotify import, play history)
 * finds or creates through here instead of keying on `source`:
 *
 * - artist: by `name_key` (Normalizer::artist).
 * - album: by artist + `name_key` (Normalizer::album).
 * - track: by a known external id first (`tracks.external_id`, or one kept as
 *   `external_id` metadata after a merge), then by album + `name_key`; a
 *   track without an album by artist + `name_key`, preferring one that has
 *   an album. Durations must be compatible (SAME_DURATION seconds, or one
 *   unknown), so two different "Intro"s on one album stay apart.
 *
 * `source`, `external_id` and `images` of an existing record are left alone;
 * a second source's external id is added as `external_id` metadata, so
 * LibraryPlayback finds the Spotify URI of a DLNA track (and the reverse).
 * The same rules decide what `library:merge-duplicates` merges (LibraryMerger).
 */
final class LibraryIdentity
{
    /** Tracks whose durations differ by more than this many seconds are different recordings. */
    public const SAME_DURATION = 5;

    public static function artist(string $name, ?string $source): Artist
    {
        $key = Normalizer::artist($name);

        return self::find(Artist::class, fn () => Artist::query()->where('name_key', $key)->orderBy('id')->first())
            ?? self::create(Artist::class, ['name' => $name, 'source' => $source],
                fn () => Artist::query()->where('name', $name)->where('source', $source)->orderBy('id')->first());
    }

    /** @param  array<string, mixed>  $attributes  only used when the album is created */
    public static function album(Artist $artist, string $name, ?string $source, array $attributes = []): Album
    {
        $key = Normalizer::album($name);

        return self::find(Album::class, fn () => Album::query()
            ->where('artist_id', $artist->id)->where('name_key', $key)->orderBy('id')->first())
            ?? self::create(Album::class, ['artist_id' => $artist->id, 'name' => $name, 'source' => $source] + $attributes,
                fn () => Album::query()->where('artist_id', $artist->id)->where('name', $name)->where('source', $source)->orderBy('id')->first());
    }

    /**
     * Finds or creates a track. A track found by its own external id (same
     * source) is updated with $attributes, as the importers always did; one
     * found by name only gets its blanks filled and the external id noted.
     *
     * @param  array<string, mixed>  $attributes  e.g. duration, images
     */
    public static function track(Artist $artist, ?Album $album, string $name, ?string $externalId, ?string $source, array $attributes = []): Track
    {
        $duration = isset($attributes['duration']) ? (int) $attributes['duration'] : null;

        if ($externalId !== null && $externalId !== '' && ($track = self::findByExternalId($externalId, $source))) {
            $own = $track->external_id === $externalId && $track->source === $source;

            if ($own) {
                $track->fill(['name' => $name, 'artist_id' => $artist->id, 'album_id' => $album?->id ?? $track->album_id] + $attributes);
            } else {
                self::fillBlanks($track, $album, $attributes);
            }

            $track->save();

            return $track;
        }

        $track = self::find(Track::class, fn () => self::match($artist->id, $album?->id, Normalizer::track($name), $duration));

        if ($track === null) {
            return Track::create([
                'artist_id' => $artist->id,
                'album_id' => $album?->id,
                'name' => $name,
                'external_id' => $externalId ?: null,
                'source' => $source,
            ] + $attributes);
        }

        self::fillBlanks($track, $album, $attributes);
        self::addExternalId($track, $externalId, $source);
        $track->save();

        return $track;
    }

    /**
     * Creates a record; when a unique index rejects it (a concurrent queue
     * worker created it first, or an existing row has a stale `name_key`),
     * returns the existing row found by $existing instead.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private static function create(string $model, array $attributes, \Closure $existing): Model
    {
        try {
            return $model::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            return $existing() ?? throw $e;
        }
    }

    /** A track by an external id: its own (`tracks.external_id` + `source`), or one kept from a merged record. */
    public static function findByExternalId(string $externalId, ?string $source): ?Track
    {
        $track = Track::query()->where('external_id', $externalId)->where('source', $source)->first();

        if ($track !== null) {
            return $track;
        }

        $id = Metadata::query()
            ->where('metadatable_type', Track::class)
            ->where('key', 'external_id')
            ->where('source', $source ?? 'unknown')
            ->where('value', $externalId)
            ->value('metadatable_id');

        return $id ? Track::find($id) : null;
    }

    /**
     * The same track by name: on the album (or, without one, an album-less
     * track of the artist); without an album, the artist's oldest compatible
     * track on an album wins over an album-less one.
     */
    public static function match(int $artistId, ?int $albumId, string $key, ?int $duration, ?int $exceptId = null): ?Track
    {
        $compatible = fn (Collection $tracks) => $tracks->first(fn (Track $t) => self::sameDuration($t->duration, $duration));
        $base = fn () => Track::query()->where('name_key', $key)->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))->orderBy('id');

        if ($albumId !== null) {
            return $compatible($base()->where('album_id', $albumId)->get())
                ?? $compatible($base()->where('artist_id', $artistId)->whereNull('album_id')->get());
        }

        $candidates = $base()->where('artist_id', $artistId)->get();

        return $compatible($candidates->whereNotNull('album_id'))
            ?? $compatible($candidates->whereNull('album_id'));
    }

    public static function sameDuration(?int $a, ?int $b): bool
    {
        return !$a || !$b || abs($a - $b) <= self::SAME_DURATION;
    }

    /** Keeps another source's external id on $track (as `external_id` metadata) unless it is the track's own. */
    public static function addExternalId(Track $track, ?string $externalId, ?string $source): void
    {
        if ($externalId === null || $externalId === '') {
            return;
        }

        if ($track->external_id === null || $track->external_id === '') {
            // Nothing else holds this id (it was looked up first), so the track can take it as its own.
            $track->external_id = $externalId;
            $track->source = $source;

            return;
        }

        self::keepExternalId($track, $externalId, $source);
    }

    /** Stores another source's external id on $track as `external_id` metadata (source = that source). */
    public static function keepExternalId(Track $track, string $externalId, ?string $source): void
    {
        if ($track->external_id === $externalId && $track->source === $source) {
            return;
        }

        Metadata::query()->firstOrCreate(
            [
                'metadatable_type' => Track::class,
                'metadatable_id' => $track->id,
                'key' => 'external_id',
                'source' => $source ?? 'unknown',
                'value' => $externalId,
            ],
            ['type' => 'string'],
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private static function fillBlanks(Track $track, ?Album $album, array $attributes): void
    {
        if ($track->album_id === null && $album !== null) {
            $track->album_id = $album->id;
        }

        foreach ($attributes as $key => $value) {
            if (($track->{$key} === null || $track->{$key} === [] || $track->{$key} === 0) && $value !== null) {
                $track->{$key} = $value;
            }
        }
    }

    /**
     * Runs $lookup; when it misses while rows without a key exist (added
     * before keys did), gives those their key and looks again.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private static function find(string $model, \Closure $lookup): ?Model
    {
        $found = $lookup();

        if ($found === null && $model::query()->whereNull('name_key')->exists()) {
            self::fillMissingKeys($model);
            $found = $lookup();
        }

        return $found;
    }

    /** Gives every row of $model without a `name_key` its key. Only the derived key column is written. */
    public static function fillMissingKeys(string $model): int
    {
        $count = 0;

        $model::query()->whereNull('name_key')->select('id', 'name')->chunkById(500, function ($rows) use ($model, &$count) {
            foreach ($rows as $row) {
                $model::query()->whereKey($row->id)->update(['name_key' => self::keyFor($model, $row->name)]);
                $count++;
            }
        });

        return $count;
    }

    public static function keyFor(string $model, ?string $name): string
    {
        return match ($model) {
            Artist::class => Normalizer::artist($name),
            Album::class => Normalizer::album($name),
            Track::class => Normalizer::track($name),
        };
    }
}
