<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LocalCover;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;

/**
 * What an admin sets by hand on an artist (settings page). Every value is a metadata row with source `manual`,
 * which ReadsMetadata::metaValue() prefers over any automatic source; enrichment writes its own rows and never
 * touches these, so a re-fetch can't undo a choice. Resetting deletes the row.
 */
final class ArtistOverrides
{
    public static function set(Artist $artist, string $key, string $value, string $type = 'string'): void
    {
        Enrichment::save($artist, $key, $value, $type, Metadata::MANUAL);
        $artist->unsetRelation('metadata');
    }

    public static function clear(Artist $artist, string $key): void
    {
        Metadata::query()->where('metadatable_type', $artist->getMorphClass())->where('metadatable_id', $artist->id)
            ->where('key', $key)->where('source', Metadata::MANUAL)->delete();
        $artist->unsetRelation('metadata');
    }

    public static function isSet(Artist $artist, string $key): bool
    {
        return Metadata::query()->where('metadatable_type', $artist->getMorphClass())->where('metadatable_id', $artist->id)
            ->where('key', $key)->where('source', Metadata::MANUAL)->exists();
    }

    /** @param  string|null  $url  null goes back to the automatic photo */
    public static function setPhoto(Artist $artist, ?string $url): void
    {
        $url === null ? self::clear($artist, 'photo') : self::set($artist, 'photo', $url);
    }

    /** Hides or shows one wide picture in the hero; returns whether it is now hidden. */
    public static function toggleHidden(Artist $artist, string $url): bool
    {
        $hidden = $artist->hiddenImages();
        $nowHidden = !in_array($url, $hidden, true);
        $hidden = $nowHidden ? [...$hidden, $url] : array_values(array_diff($hidden, [$url]));

        $hidden === [] ? self::clear($artist, 'hidden_images') : self::set($artist, 'hidden_images', json_encode($hidden), 'json');

        return $nowHidden;
    }

    /** Hides every wide picture, or shows them all again. */
    public static function setAllHidden(Artist $artist, bool $hidden): void
    {
        $hidden ? self::set($artist, 'hidden_images', json_encode($artist->wideImageUrls()), 'json') : self::clear($artist, 'hidden_images');
    }

    /** Adds a picture by http(s) URL; false when it isn't one. */
    public static function addImageUrl(Artist $artist, string $url): bool
    {
        $url = trim($url);

        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
            return false;
        }

        self::addImage($artist, $url);

        return true;
    }

    /** Adds an uploaded picture; false when it isn't a usable image. */
    public static function addUpload(Artist $artist, string $bytes): bool
    {
        $url = LocalCover::store($bytes);

        if ($url === null) {
            return false;
        }

        self::addImage($artist, $url);

        return true;
    }

    private static function addImage(Artist $artist, string $url): void
    {
        $images = $artist->extraImages();

        if (!in_array($url, $images, true)) {
            self::set($artist, 'extra_images', json_encode([...$images, $url]), 'json');
        }
    }

    /** Removes an added picture (and its photo choice or hidden mark). */
    public static function removeImage(Artist $artist, string $url): void
    {
        $images = array_values(array_diff($artist->extraImages(), [$url]));
        $images === [] ? self::clear($artist, 'extra_images') : self::set($artist, 'extra_images', json_encode($images), 'json');

        if ($artist->metaValue('photo') === $url) {
            self::clear($artist, 'photo');
        }
    }

    /**
     * Every picture that can be the artist's photo: TheAudioDB's thumb, Spotify's, the pictures added by hand, the wide
     * ones and the cover of the most played album.
     *
     * @return list<array{url: string, label: string, removable: bool}>
     */
    public static function photoCandidates(Artist $artist): array
    {
        $images = (array) $artist->metaJson('images');
        $extra = $artist->extraImages();
        $labels = ['thumb' => 'TheAudioDB photo', 'fanart' => 'TheAudioDB fanart', 'fanart2' => 'TheAudioDB fanart 2', 'fanart3' => 'TheAudioDB fanart 3',
            'fanart4' => 'TheAudioDB fanart 4', 'wide_thumb' => 'TheAudioDB wide'];

        $found = [];
        foreach ($labels as $key => $label) {
            if (!empty($images[$key])) {
                $found[] = ['url' => $images[$key], 'label' => $label, 'removable' => false];
            }
        }
        if ($spotify = $artist->metaValue('spotify_image')) {
            $found[] = ['url' => $spotify, 'label' => 'Spotify photo', 'removable' => false];
        }
        foreach ($extra as $url) {
            $found[] = ['url' => $url, 'label' => LocalCover::isUrl($url) ? 'Uploaded' : 'Added by link', 'removable' => true];
        }
        if ($cover = \App\Domain\Artwork\LibraryArtwork::coverUrl($artist->coverAlbum()?->images)) {
            $found[] = ['url' => $cover, 'label' => 'Top album cover', 'removable' => false];
        }

        $seen = [];

        return array_values(array_filter($found, fn ($c) => !isset($seen[$c['url']]) && ($seen[$c['url']] = true)));
    }

    /** The artist types MusicBrainz knows, as `artist_type` stores them. */
    public const TYPES = ['person', 'group', 'orchestra', 'choir', 'character', 'other'];

    /** The countries, bio, type and tags an admin can correct; a blank value goes back to the automatic one. */
    public static function setDetails(Artist $artist, array $details): void
    {
        $text = fn (string $key) => trim((string) ($details[$key] ?? ''));
        $list = fn (string $key) => array_values(array_unique(array_filter(array_map('trim', explode(',', $text($key))))));

        foreach (['bio' => 'bio', 'country' => 'country'] as $field => $key) {
            $text($field) === '' ? self::clear($artist, $key) : self::set($artist, $key, $text($field));
        }

        in_array($text('type'), self::TYPES, true) ? self::set($artist, 'artist_type', $text('type')) : self::clear($artist, 'artist_type');

        $tags = $list('tags');
        $tags === [] ? self::clear($artist, 'tags') : self::set($artist, 'tags', json_encode($tags, JSON_UNESCAPED_UNICODE), 'json');

        self::setGenres($artist, $list('genres'));
    }

    /** Replaces the artist's genres by these; none goes back to what the sources reported. */
    public static function setGenres(Artist $artist, array $genres): void
    {
        $artist->genreRelation()->detach();

        if ($genres !== []) {
            self::set($artist, 'genres', json_encode($genres, JSON_UNESCAPED_UNICODE), 'json');

            return;
        }

        self::clear($artist, 'genres');
        foreach ($artist->metadata()->where('key', 'genres')->get() as $row) {
            GenreSync::sync($artist, json_decode($row->value, true) ?: [], $row->source);
        }
    }

    /** Which library the artist's pages open on when the visit names none: files | dlna | spotify, null = automatic. */
    public static function setDefaultSource(Artist $artist, ?string $source): void
    {
        in_array($source, ['files', 'dlna', 'spotify'], true) ? self::set($artist, 'default_source', $source) : self::clear($artist, 'default_source');
    }
}
