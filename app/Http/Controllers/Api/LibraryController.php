<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Library\LibraryPlayback;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Library browsing and favorites for client devices. Shapes are documented
 * in docs/api/library.md; artwork is LibraryItemArtwork's small object.
 */
class LibraryController extends Controller
{
    public const ARTISTS_PAGE_SIZE = 50;

    /** Artist name for sorting: lower case, without a leading "The ". */
    private const ARTIST_SORT_NAME = "LOWER(CASE WHEN LOWER(artists.name) LIKE 'the %' THEN SUBSTR(artists.name, 5) ELSE artists.name END)";

    public function artists(Request $request): JsonResponse
    {
        $request->validate(['cursor' => ['nullable', 'string', 'max:200']]);
        $offset = $this->decodeCursor($request->query('cursor'));

        $artists = $this->artistItems(Artist::query()->whereHas('albums'))
            ->orderByRaw(self::ARTIST_SORT_NAME)
            ->orderBy('artists.id')
            ->offset($offset)
            ->limit(self::ARTISTS_PAGE_SIZE + 1)
            ->get();

        $hasMore = $artists->count() > self::ARTISTS_PAGE_SIZE;

        return response()->json([
            'data' => $artists->take(self::ARTISTS_PAGE_SIZE)->map(fn (Artist $artist) => $this->artistItem($artist))->values(),
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + self::ARTISTS_PAGE_SIZE) : null,
        ]);
    }

    public function artist(Artist $artist): JsonResponse
    {
        $albums = $artist->albums()->with('artist')->get()
            ->sortBy([
                fn (Album $a, Album $b) => ($b->released_at?->year ?? PHP_INT_MIN) <=> ($a->released_at?->year ?? PHP_INT_MIN),
                fn (Album $a, Album $b) => strcasecmp($a->name, $b->name),
            ]);

        return response()->json([
            'id' => $artist->id,
            'name' => $artist->name,
            'favorite' => $artist->favorited_at !== null,
            'albums' => $albums->map(fn (Album $album) => $this->albumItem($album))->values(),
        ]);
    }

    public function album(Request $request, Album $album): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;

        $album->load('artist');
        $tracks = LibraryPlayback::albumTracks($album);
        $items = $tracks->map(fn (Track $track) => [
            'id' => $track->id,
            'name' => $track->name,
            'duration' => $track->duration,
            'playable' => LibraryPlayback::trackPlayable($track, $device),
        ])->values();

        return response()->json([
            'id' => $album->id,
            'name' => $album->name,
            'artist' => $album->artist ? ['id' => $album->artist->id, 'name' => $album->artist->name] : null,
            'year' => $album->released_at?->year,
            'artwork' => LibraryItemArtwork::forImages($album->images),
            'favorite' => $album->favorited_at !== null,
            'playable' => $items->contains('playable', true),
            'tracks' => $items,
        ]);
    }

    /** Most recently played distinct albums, newest first. */
    public function recent(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $albums = Album::query()
            ->joinSub(LibraryArtwork::lastPlayedPerAlbum(), 'recent', 'recent.album_id', '=', 'albums.id')
            ->with('artist')
            ->select('albums.*')
            ->orderByDesc('recent.last_played_at')
            ->orderByDesc('albums.id')
            ->limit($request->integer('limit', 30))
            ->get();

        return response()->json(['data' => $albums->map(fn (Album $album) => $this->albumItem($album))->values()]);
    }

    /** Favorite albums and artists, most recently favorited first. */
    public function favorites(): JsonResponse
    {
        $albums = Album::query()->whereNotNull('favorited_at')->with('artist')
            ->orderByDesc('favorited_at')->orderByDesc('id')->get();

        $artists = $this->artistItems(Artist::query()->whereNotNull('favorited_at'))
            ->orderByDesc('favorited_at')->orderByDesc('artists.id')->get();

        return response()->json([
            'albums' => $albums->map(fn (Album $album) => $this->albumItem($album))->values(),
            'artists' => $artists->map(fn (Artist $artist) => $this->artistItem($artist))->values(),
        ]);
    }

    public function favoriteAlbum(Request $request, Album $album): JsonResponse
    {
        return response()->json(['favorite' => $this->setFavorite($request, $album)]);
    }

    public function favoriteArtist(Request $request, Artist $artist): JsonResponse
    {
        return response()->json(['favorite' => $this->setFavorite($request, $artist)]);
    }

    /** Keeps the original favorited_at when an already favorite item is favorited again. */
    private function setFavorite(Request $request, Album|Artist $model): bool
    {
        $request->validate(['favorite' => ['required', 'boolean']]);
        $favorite = $request->boolean('favorite');

        $model->update(['favorited_at' => $favorite ? ($model->favorited_at ?? now()) : null]);

        return $favorite;
    }

    /** Eager loads what artistItem() reads: album count, and albums for the cover. */
    private function artistItems(Builder $query): Builder
    {
        return $query
            ->withCount('albums')
            ->with(['albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')]);
    }

    private function artistItem(Artist $artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'album_count' => (int) $artist->albums_count,
            'artwork' => LibraryItemArtwork::forImages($artist->coverAlbum()?->images),
            'favorite' => $artist->favorited_at !== null,
        ];
    }

    private function albumItem(Album $album): array
    {
        return [
            'id' => $album->id,
            'name' => $album->name,
            'artist_name' => $album->artist?->name,
            'year' => $album->released_at?->year,
            'artwork' => LibraryItemArtwork::forImages($album->images),
            'favorite' => $album->favorited_at !== null,
        ];
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:'.$offset), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !preg_match('/^o:(\d{1,9})$/', $decoded, $m)) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return (int) $m[1];
    }
}
