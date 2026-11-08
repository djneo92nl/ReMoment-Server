<?php

namespace App\Domain\Export;

use App\Models\Export;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/** The tracks of an export's selection, in export order: album order, playlist order, artist by album, or the given list. */
final class ExportSelection
{
    /** @return Collection<int, Track> */
    public static function tracks(Export $export): Collection
    {
        $with = ['artist', 'album.artist', 'metadata'];

        return match ($export->selection_type) {
            'album' => self::albumOrder(Track::with($with)->where('album_id', $export->selection_id)->get()),
            'artist' => Album::where('artist_id', $export->selection_id)->orderBy('released_at')->orderBy('name')->pluck('id')
                ->flatMap(fn ($id) => self::albumOrder(Track::with($with)->where('album_id', $id)->get()))->values(),
            'playlist' => Playlist::findOrFail($export->selection_id)->tracks()->with($with)->get(),
            'tracks' => self::inOrder(Track::with($with)->whereIn('id', $export->track_ids ?? [])->get(), $export->track_ids ?? []),
            default => throw new \InvalidArgumentException("Unknown export selection {$export->selection_type}."),
        };
    }

    public static function title(Export $export): string
    {
        return match ($export->selection_type) {
            'album' => Album::find($export->selection_id)?->name,
            'artist' => Artist::find($export->selection_id)?->name,
            'playlist' => Playlist::find($export->selection_id)?->name,
            default => null,
        } ?? 'Export';
    }

    /** @param Collection<int, Track> $tracks */
    private static function albumOrder(Collection $tracks): Collection
    {
        return $tracks->sortBy([
            fn ($a, $b) => (int) $a->metaValue('disc_number') <=> (int) $b->metaValue('disc_number'),
            fn ($a, $b) => (int) $a->metaValue('track_number') <=> (int) $b->metaValue('track_number'),
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
    }

    /** @param Collection<int, Track> $tracks @param list<int> $ids */
    private static function inOrder(Collection $tracks, array $ids): Collection
    {
        $byId = $tracks->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();
    }
}
