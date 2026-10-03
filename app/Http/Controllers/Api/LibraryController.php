<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Media\Playlist;
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

    public const PLAYLISTS_PAGE_SIZE = 50;

    /** Artists and albums listed in a genre's detail. */
    public const GENRE_ITEMS = 100;

    /** Tracks listed in a playlist's detail. */
    public const PLAYLIST_TRACKS = 200;

    /** Artist name for sorting: lower case, without a leading "The ". */
    private const ARTIST_SORT_NAME = "LOWER(CASE WHEN LOWER(artists.name) LIKE 'the %' THEN SUBSTR(artists.name, 5) ELSE artists.name END)";

    public function artists(Request $request): JsonResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string', 'max:200'],
            'letter' => ['nullable', 'string', 'regex:/^[A-Za-z#]$/'],
        ]);
        $hidden = $this->hiddenSources($request);
        $offset = $this->decodeCursor($request->query('cursor'));

        // Jump to a letter (the clients' A-Z bar): start at the first artist sorting at or after
        // it; "#" is the start (digits and symbols sort before letters). Paging continues from there.
        $letter = $request->query('letter');
        if ($letter !== null && $letter !== '#' && $request->query('cursor') === null) {
            $offset = LibrarySources::artists(Artist::query()->whereHas('albums'), $hidden)
                ->whereRaw(self::ARTIST_SORT_NAME.' < ?', [strtolower($letter)])
                ->count();
        }

        $artists = $this->artistItems(LibrarySources::artists(Artist::query()->whereHas('albums'), $hidden), $hidden)
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

    public function artist(Request $request, Artist $artist): JsonResponse
    {
        $hidden = $this->hiddenSources($request);
        $albums = LibrarySources::albums($artist->albums()->with('artist'), $hidden)->get()
            ->sortBy([
                fn (Album $a, Album $b) => ($b->released_at?->year ?? PHP_INT_MIN) <=> ($a->released_at?->year ?? PHP_INT_MIN),
                fn (Album $a, Album $b) => strcasecmp($a->name, $b->name),
            ]);

        return response()->json([
            'id' => $artist->id,
            'name' => $artist->name,
            'favorite' => $artist->favorited_at !== null,
            'genres' => $artist->genres(),
            'details' => $artist->details(),
            'albums' => $albums->map(fn (Album $album) => $this->albumItem($album))->values(),
        ]);
    }

    public function album(Request $request, Album $album): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;
        $hidden = $this->hiddenSources($request);

        $album->load('artist');
        $tracks = LibraryPlayback::albumTracks($album, $hidden);
        // The playback keys plus what the track details read, so neither costs a query per track.
        $tracks->load(['genreRelation', 'metadata' => fn ($q) => $q->whereIn('key', array_unique([...LibraryPlayback::PLAYBACK_METADATA, ...Track::DISPLAY_METADATA]))]);
        $items = $tracks->map(fn (Track $track) => [
            'id' => $track->id,
            'name' => $track->name,
            'duration' => $track->duration,
            'playable' => LibraryPlayback::trackPlayable($track, $device),
            'details' => $track->details(),
        ])->values();

        return response()->json([
            'id' => $album->id,
            'name' => $album->name,
            'artist' => $album->artist ? ['id' => $album->artist->id, 'name' => $album->artist->name] : null,
            'year' => $album->released_at?->year,
            'artwork' => LibraryItemArtwork::forImages($album->images),
            'favorite' => $album->favorited_at !== null,
            'genres' => $album->genres(),
            'details' => $album->details(),
            'playable' => $items->contains('playable', true),
            'tracks' => $items,
        ]);
    }

    /** Genres with artists or albums, most artists first. */
    public function genres(Request $request): JsonResponse
    {
        $hidden = $this->hiddenSources($request);
        $genres = Genre::query()->withCount([
            'artists' => fn ($q) => LibrarySources::artists($q, $hidden),
            'albums' => fn ($q) => LibrarySources::albums($q, $hidden),
        ])->get()
            ->filter(fn (Genre $genre) => $genre->artists_count > 0 || $genre->albums_count > 0)
            ->sortBy([['artists_count', 'desc'], ['albums_count', 'desc'], ['name', 'asc']])
            ->values();

        return response()->json(['data' => $genres->map(fn (Genre $genre) => [
            'slug' => $genre->slug,
            'name' => $genre->name,
            'artist_count' => $genre->artists_count,
            'album_count' => $genre->albums_count,
        ])]);
    }

    /** A genre's artists (alphabetical, "The " ignored) and albums (newest first), up to GENRE_ITEMS of each. */
    public function genre(Request $request, Genre $genre): JsonResponse
    {
        $hidden = $this->hiddenSources($request);
        $artists = $this->artistItems(LibrarySources::artists($genre->artists()->getQuery(), $hidden), $hidden)
            ->whereHas('albums')
            ->orderByRaw(self::ARTIST_SORT_NAME)
            ->limit(self::GENRE_ITEMS)
            ->get();

        $albums = LibrarySources::albums($genre->albums()->with('artist'), $hidden)
            ->orderByRaw('albums.released_at IS NULL')->orderByDesc('albums.released_at')->orderBy('albums.name')
            ->limit(self::GENRE_ITEMS)
            ->get();

        return response()->json([
            'slug' => $genre->slug,
            'name' => $genre->name,
            'artists' => $artists->map(fn (Artist $artist) => $this->artistItem($artist))->values(),
            'albums' => $albums->map(fn (Album $album) => $this->albumItem($album))->values(),
        ]);
    }

    /** Playlists with tracks, recently played first (Playlist::withTracksByRecency). */
    public function playlists(Request $request): JsonResponse
    {
        $request->validate(['cursor' => ['nullable', 'string', 'max:200']]);
        $offset = $this->decodeCursor($request->query('cursor'));
        $hidden = $this->hiddenSources($request);

        $playlists = LibrarySources::playlists(Playlist::query(), $hidden)
            ->withTracksByRecency()
            ->withCount(['tracks' => fn ($q) => LibrarySources::tracks($q, $hidden)])
            ->with(['metadata' => fn ($q) => $q->where('key', 'spotify_owner')])
            ->offset($offset)
            ->limit(self::PLAYLISTS_PAGE_SIZE + 1)
            ->get();

        $hasMore = $playlists->count() > self::PLAYLISTS_PAGE_SIZE;
        $playlists = $playlists->take(self::PLAYLISTS_PAGE_SIZE);
        $artwork = PlaylistArtwork::choose($playlists);

        return response()->json([
            'data' => $playlists->map(fn (Playlist $playlist) => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'track_count' => (int) $playlist->tracks_count,
                'owner' => $this->owner($playlist),
                'artwork' => isset($artwork[$playlist->id])
                    ? LibraryItemArtwork::forUrl($artwork[$playlist->id]['url'], $artwork[$playlist->id]['sources'])
                    : null,
            ])->values(),
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + self::PLAYLISTS_PAGE_SIZE) : null,
        ]);
    }

    public function playlist(Request $request, Playlist $playlist): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;

        $hidden = $this->hiddenSources($request);

        $playlist->load(['metadata' => fn ($q) => $q->where('key', 'spotify_owner')]);
        $items = LibraryPlayback::playlistTracks($playlist, self::PLAYLIST_TRACKS, $hidden)->map(fn (Track $track) => [
            'id' => $track->id,
            'name' => $track->name,
            'artist_name' => $track->artist?->name,
            'duration' => $track->duration,
            'playable' => LibraryPlayback::trackPlayable($track, $device),
        ])->values();

        return response()->json([
            'id' => $playlist->id,
            'name' => $playlist->name,
            'owner' => $this->owner($playlist),
            'artwork' => PlaylistArtwork::itemFor($playlist),
            'playable' => $items->contains('playable', true),
            'tracks' => $items,
        ]);
    }

    /** The Spotify owner's display name (from the eager loaded metadata); null for local playlists. */
    private function owner(Playlist $playlist): ?string
    {
        return $playlist->metadata->firstWhere('key', 'spotify_owner')?->value ?: null;
    }

    /** Most recently played distinct albums, newest first. */
    public function recent(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $albums = LibrarySources::albums(Album::query(), $this->hiddenSources($request))
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
    public function favorites(Request $request): JsonResponse
    {
        $hidden = $this->hiddenSources($request);

        $albums = LibrarySources::albums(Album::query()->whereNotNull('favorited_at')->with('artist'), $hidden)
            ->orderByDesc('favorited_at')->orderByDesc('id')->get();

        $artists = $this->artistItems(LibrarySources::artists(Artist::query()->whereNotNull('favorited_at'), $hidden), $hidden)
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
    private function artistItems(Builder $query, array $hidden = []): Builder
    {
        return $query
            ->withCount(['albums' => fn ($q) => LibrarySources::albums($q, $hidden)])
            ->with(['albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')]);
    }

    /**
     * The sources the requesting client hides (`?client={api_token}`, see
     * docs/api/library.md); none without the parameter. Radio is not part
     * of the library, so it has no effect here.
     */
    private function hiddenSources(Request $request): array
    {
        $request->validate(['client' => ['nullable', 'string', 'max:100']]);

        return $request->filled('client')
            ? LibrarySources::hiddenFor(Client::where('api_token', $request->query('client'))->firstOrFail())
            : [];
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
