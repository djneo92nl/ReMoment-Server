<?php

namespace App\Domain\Library;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\ArtworkTint;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Export\ExportSelection;
use App\Models\Export;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\TrackFile;

/**
 * What the library picker (`Livewire\LibraryPicker`) holds: a list of picks `{type: artist|album|track|playlist, id}`.
 * This class describes picks for display and expands them to track ids, so any tool that needs a selection from the
 * library (export now, others later) uses the same code. Ids of merged records are resolved through `IdAliases`.
 */
final class LibraryPicks
{
    public const TYPES = ['artist', 'album', 'track', 'playlist'];

    /** Average size of a track in MB for the rough estimate in the picker (an export gives the real figure). */
    public const AVERAGE_MB = 4.2;

    /**
     * Cleans a list from the browser: known types, integer ids, no duplicates, aliases resolved.
     *
     * @return list<array{type: string, id: int}>
     */
    public static function normalize(array $picks): array
    {
        $aliases = app(IdAliases::class);
        $seen = [];

        foreach ($picks as $pick) {
            $type = $pick['type'] ?? null;
            $id = (int) ($pick['id'] ?? 0);
            if (!in_array($type, self::TYPES, true) || $id <= 0) {
                continue;
            }
            $id = $aliases->resolve($type, $id);
            $seen[$type.':'.$id] = ['type' => $type, 'id' => $id];
        }

        return array_values($seen);
    }

    /**
     * The picks as rows for the basket: name, subtitle, track count and image.
     *
     * @param  list<array{type: string, id: int}>  $picks
     * @param  list<string>  $hidden  hidden library sources (see LibrarySources)
     * @return list<array<string, mixed>>
     */
    public static function describe(array $picks, array $hidden = [], bool $ownFilesOnly = false): array
    {
        $rows = [];

        foreach (self::normalize($picks) as $pick) {
            $row = self::row($pick['type'], $pick['id'], $hidden, $ownFilesOnly);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Track ids of the picks in order (an artist by album, an album by disc and track number, a playlist in its order),
     * each track once.
     *
     * @param  list<array{type: string, id: int}>  $picks
     * @return list<int>
     */
    public static function trackIds(array $picks): array
    {
        $ids = [];

        foreach (self::normalize($picks) as $pick) {
            $export = new Export($pick['type'] === 'track'
                ? ['selection_type' => 'tracks', 'track_ids' => [$pick['id']]]
                : ['selection_type' => $pick['type'], 'selection_id' => $pick['id']]);

            foreach (ExportSelection::tracks($export) as $track) {
                $ids[$track->id] = $track->id;
            }
        }

        return array_values($ids);
    }

    /** @return array<string, mixed>|null */
    private static function row(string $type, int $id, array $hidden, bool $own): ?array
    {
        $base = ['type' => $type, 'id' => $id, 'key' => $type.':'.$id];

        $row = match ($type) {
            'artist' => ($artist = Artist::find($id)) ? $base + [
                'name' => $artist->name,
                'sub' => 'Artist',
                'tracks' => self::count(Track::query()->whereHas('album', fn ($q) => $q->where('artist_id', $id)), $hidden, $own),
                'art' => $artist->avatarUrl(),
            ] : null,
            'album' => ($album = Album::with('artist')->find($id)) ? $base + [
                'name' => $album->name,
                'sub' => $album->artist?->name ?? 'Album',
                'tracks' => self::count(Track::query()->where('album_id', $id), $hidden, $own),
                'art' => LibraryArtwork::coverUrl($album->images),
            ] : null,
            'track' => ($track = Track::with(['artist', 'album'])->find($id)) ? $base + [
                'name' => $track->name,
                'sub' => trim(($track->artist?->name ?? '').($track->album ? ' · '.$track->album->name : ''), ' ·'),
                'tracks' => $own && !self::count(Track::query()->whereKey($id), $hidden, true) ? 0 : 1,
                'art' => LibraryArtwork::coverUrl($track->album?->images),
            ] : null,
            'playlist' => ($playlist = Playlist::find($id)) ? $base + [
                'name' => $playlist->name,
                'sub' => 'Playlist',
                'tracks' => self::count($playlist->tracks()->getQuery(), $hidden, $own),
                'art' => PlaylistArtwork::itemFor($playlist)['proxy_120'] ?? null,
            ] : null,
        };

        return $row === null ? null : $row + ['tint' => self::tint($row['art'])];
    }

    /** The `style` value tinting a row from its cover's colours ('' while the cover is not processed). */
    public static function tint(?string $art): string
    {
        return $art && !str_contains($art, '/storage/') ? ArtworkTint::style(ArtworkCache::get($art)['colors'] ?? null) : '';
    }

    private static function count($query, array $hidden, bool $own = false): int
    {
        $query = LibrarySources::tracks($query, $hidden);

        return ($own ? self::ownFiles($query) : $query)->count();
    }

    /**
     * Narrows a tracks query to tracks that have an own audio file present on this server (what an export or a
     * download can actually use; DLNA and streaming-only tracks have none).
     */
    public static function ownFiles($query)
    {
        return $query->whereExists(fn ($f) => $f->selectRaw('1')->from('track_files')
            ->join('files', 'files.id', '=', 'track_files.file_id')
            ->whereColumn('track_files.track_id', 'tracks.id')
            ->where('track_files.role', TrackFile::ORIGINAL)
            ->where('files.status', File::PRESENT));
    }
}
