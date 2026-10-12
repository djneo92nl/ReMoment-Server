<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Library\LeadingSource;
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
use App\Models\Play;
use App\Models\RadioStation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    public const ALBUMS_PAGE_SIZE = 60;

    public const PLAYLISTS_PAGE_SIZE = 50;

    /** Artists and albums listed in a genre's detail. */
    public const GENRE_ITEMS = 100;

    /** Hits per kind in a search. */
    public const SEARCH_ARTISTS = 10;

    public const SEARCH_ALBUMS = 15;

    public const SEARCH_TRACKS = 20;

    /** Tracks listed in a playlist's detail. */
    public const PLAYLIST_TRACKS = 200;

    /** Artist name for sorting: lower case, without a leading "The ". */
    private const ARTIST_SORT_NAME = Artist::SORT_NAME_SQL;

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
        $this->preloadArtwork(artists: $artists->take(self::ARTISTS_PAGE_SIZE));

        return response()->json([
            'data' => $artists->take(self::ARTISTS_PAGE_SIZE)->map(fn (Artist $artist) => $this->artistItem($artist))->values(),
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + self::ARTISTS_PAGE_SIZE) : null,
        ]);
    }

    /** All albums with tracks, paged: `sort` is name (default), artist ("The " ignored), year (newest first), added (newest first) or plays. */
    public function albums(Request $request): JsonResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'in:name,artist,year,added,plays'],
        ]);
        $hidden = $this->hiddenSources($request);
        $offset = $this->decodeCursor($request->query('cursor'));

        $query = LibrarySources::albums(Album::query(), $hidden)->whereHas('tracks')->with('artist');

        match ($request->query('sort', 'name')) {
            'artist' => $query->join('artists', 'artists.id', '=', 'albums.artist_id')
                ->orderByRaw(self::ARTIST_SORT_NAME)->orderBy('albums.name')->select('albums.*'),
            'year' => $query->orderByRaw('albums.released_at IS NULL')->orderByDesc('albums.released_at')->orderBy('albums.name'),
            'added' => $query->orderByDesc('albums.created_at')->orderByDesc('albums.id'),
            'plays' => $query->withCount('plays')->orderByDesc('plays_count')->orderByDesc('albums.created_at'),
            default => $query->orderBy('albums.name'),
        };

        $albums = $query->orderBy('albums.id')
            ->offset($offset)
            ->limit(self::ALBUMS_PAGE_SIZE + 1)
            ->get();

        $hasMore = $albums->count() > self::ALBUMS_PAGE_SIZE;
        $this->preloadArtwork(albums: $albums->take(self::ALBUMS_PAGE_SIZE));

        return response()->json([
            'data' => $albums->take(self::ALBUMS_PAGE_SIZE)->map(fn (Album $album) => $this->albumItem($album))->values(),
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + self::ALBUMS_PAGE_SIZE) : null,
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
        $this->preloadArtwork(albums: $albums);

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
        $this->preloadArtwork(albums: [$album]);
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
            'sources' => LibrarySources::ofTracks($tracks),
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
        $this->preloadArtwork(albums: $albums, artists: $artists);

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
        $this->preloadArtwork(choices: $artwork);

        return response()->json([
            'data' => $playlists->map(fn (Playlist $playlist) => $this->playlistItem($playlist, $artwork))->values(),
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + self::PLAYLISTS_PAGE_SIZE) : null,
        ]);
    }

    public function playlist(Request $request, Playlist $playlist): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;

        $hidden = $this->hiddenSources($request);

        $playlist->load(['metadata' => fn ($q) => $q->where('key', 'spotify_owner')]);
        $choice = PlaylistArtwork::choose(collect([$playlist]))[$playlist->id] ?? null;
        $this->preloadArtwork(choices: [$choice]);
        $tracks = LibraryPlayback::playlistTracks($playlist, self::PLAYLIST_TRACKS, $hidden);
        $items = $tracks->map(fn (Track $track) => [
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
            'artwork' => $choice === null ? null : LibraryItemArtwork::forUrl($choice['url'], $choice['sources']),
            'sources' => LibrarySources::ofTracks($tracks),
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
        $this->preloadArtwork(albums: $albums);

        return response()->json(['data' => $albums->map(fn (Album $album) => $this->albumItem($album))->values()]);
    }

    /**
     * The libraries with content for this client (docs/api/library.md): LibrarySources::LIBRARIES
     * minus the client's hidden ones and those without albums or playlists, each with its counts
     * and a representative cover (the library's most recently played album, else its newest).
     * With `device_id`, each also says whether that device can play it (LibrarySources::playableOn).
     */
    public function libraries(Request $request): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;
        $clientHidden = $this->clientHidden($request);
        $data = [];
        $covers = [];

        foreach (LibrarySources::forDisplay() as $id => $library) {
            if (in_array($id, $clientHidden, true)) {
                continue;
            }
            $hidden = [...$clientHidden, ...LibrarySources::allBut($id)];

            $albumCount = LibrarySources::albums(Album::query(), $hidden)->count();
            $playlistCount = LibrarySources::playlists(Playlist::query()->whereHas('tracks'), $hidden)->count();
            if ($albumCount === 0 && $playlistCount === 0) {
                continue;
            }

            $cover = LibrarySources::albums(Album::query()->whereNotNull('albums.images'), $hidden)
                ->leftJoinSub(LibraryArtwork::lastPlayedPerAlbum(), 'recent', 'recent.album_id', '=', 'albums.id')
                ->select('albums.*')
                ->orderByRaw('CASE WHEN recent.last_played_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('recent.last_played_at')
                ->orderByDesc('albums.id')
                ->first();

            $covers[$id] = $cover;
            $data[] = [
                'id' => $id,
                'name' => $library['name'],
                'kind' => $library['kind'],
                'album_count' => $albumCount,
                'artist_count' => LibrarySources::artists(Artist::query()->whereHas('albums'), $hidden)->count(),
                'playlist_count' => $playlistCount,
                'artwork' => null,
                ...($device ? ['playable' => LibrarySources::playableOn($id, $device)] : []),
            ];
        }

        // One cache read for all libraries' covers.
        $this->preloadArtwork(albums: $covers);
        foreach ($data as &$item) {
            $item['artwork'] = LibraryItemArtwork::forImages($covers[$item['id']]?->images);
        }
        unset($item);

        return response()->json(['data' => $data]);
    }

    /**
     * Recently played albums, playlists and radio stations in one list, newest first (docs/api/library.md).
     * Each kind is limited to `limit` before merging, so the merged list is complete.
     */
    public function recentItems(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $limit = $request->integer('limit', 30);
        $hidden = $this->clientHidden($request);

        $items = collect();

        $albums = LibrarySources::albums(Album::query(), $hidden)
            ->joinSub(LibraryArtwork::lastPlayedPerAlbum(), 'recent', 'recent.album_id', '=', 'albums.id')
            ->with('artist')
            ->select('albums.*', 'recent.last_played_at as recent_played_at')
            ->orderByDesc('recent.last_played_at')
            ->orderByDesc('albums.id')
            ->limit($limit)
            ->get();
        $this->preloadArtwork(albums: $albums);
        $playedFrom = $this->albumPlayedFrom($albums->pluck('id')->all());
        foreach ($albums as $album) {
            $played = $playedFrom[$album->id] ?? null;
            $library = $played !== null && !in_array($played, $hidden, true)
                ? $played
                : $this->leadingLibrary($album->tracks(), $hidden);
            $items->push([
                'type' => 'album',
                'library' => $library,
                'played_at' => Carbon::parse($album->recent_played_at)->toIso8601String(),
                'album' => $this->albumItem($album),
                'playlist' => null,
                'station' => null,
            ]);
        }

        $playlists = LibrarySources::playlists(Playlist::query()->whereNotNull('last_played_at'), $hidden)
            ->withCount(['tracks' => fn ($q) => LibrarySources::tracks($q, $hidden)])
            ->with(['metadata' => fn ($q) => $q->where('key', 'spotify_owner')])
            ->orderByDesc('last_played_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
        $artwork = PlaylistArtwork::choose($playlists);
        $this->preloadArtwork(choices: $artwork);
        foreach ($playlists as $playlist) {
            $library = LibrarySources::libraryOfSource($playlist->source);
            $items->push([
                'type' => 'playlist',
                'library' => $library !== null && !in_array($library, $hidden, true)
                    ? $library
                    : $this->leadingLibrary($playlist->tracks(), $hidden),
                'played_at' => $playlist->last_played_at->toIso8601String(),
                'album' => null,
                'playlist' => $this->playlistItem($playlist, $artwork),
                'station' => null,
            ]);
        }

        if (!in_array(LibrarySources::RADIO, $hidden, true)) {
            RadioStation::query()->with('meta')->whereNotNull('last_played_at')
                ->orderByDesc('last_played_at')->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->each(fn (RadioStation $station) => $items->push([
                    'type' => 'radio',
                    'library' => LibrarySources::RADIO,
                    'played_at' => $station->last_played_at->toIso8601String(),
                    'album' => null,
                    'playlist' => null,
                    'station' => $station->apiItem(),
                ]));
        }

        return response()->json(['data' => $items->sortByDesc('played_at')->take($limit)->values()]);
    }

    /**
     * The library each album was last played from: the library of its latest played track
     * (`tracks.source`); albums whose latest plays were not of a library track are left out.
     *
     * @param  list<int>  $albumIds
     * @return array<int, string> album id => library id
     */
    private function albumPlayedFrom(array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }

        $plays = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereIn('tracks.album_id', $albumIds)
            ->orderByDesc('plays.played_at')->orderByDesc('plays.id')
            ->get(['tracks.album_id', 'tracks.source']);

        $from = [];
        foreach ($plays as $play) {
            $from[$play->album_id] ??= LibrarySources::libraryOfSource($play->source);
        }

        return array_filter($from);
    }

    /** The leading library among those a track query is available from (and the client doesn't hide). */
    private function leadingLibrary(Relation|Builder $tracks, array $hidden): ?string
    {
        $available = array_filter(
            array_diff(LibrarySources::libraryIds(), $hidden),
            fn (string $id) => LibrarySources::availableFrom(clone $tracks, $id)->exists(),
        );

        return LibrarySources::leadingOf(array_values($available));
    }

    /**
     * Free-text search over artist, album and track names (substring, case
     * insensitive). Tracks without an album are left out: a client plays a
     * track through its album (play-album + start_track_id).
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $hidden = $this->hiddenSources($request);
        $like = '%'.addcslashes(trim($request->query('q')), '\\%_').'%';

        $artists = $this->artistItems(LibrarySources::artists(Artist::query()->whereHas('albums'), $hidden), $hidden)
            ->where('artists.name', 'like', $like)
            ->orderByRaw(self::ARTIST_SORT_NAME)
            ->limit(self::SEARCH_ARTISTS)
            ->get();

        $albums = LibrarySources::albums(Album::query()->with('artist'), $hidden)
            ->where('albums.name', 'like', $like)
            ->orderBy('albums.name')
            ->limit(self::SEARCH_ALBUMS)
            ->get();

        $tracks = LibrarySources::tracks(Track::query()->with(['artist', 'album']), $hidden)
            ->whereNotNull('tracks.album_id')
            ->where('tracks.name', 'like', $like)
            ->orderBy('tracks.name')
            ->limit(self::SEARCH_TRACKS)
            ->get();
        $this->preloadArtwork(albums: $albums->concat($tracks->pluck('album')), artists: $artists);

        return response()->json([
            'artists' => $artists->map(fn (Artist $artist) => $this->artistItem($artist))->values(),
            'albums' => $albums->map(fn (Album $album) => $this->albumItem($album))->values(),
            'tracks' => $tracks->map(fn (Track $track) => [
                'id' => $track->id,
                'name' => $track->name,
                'artist_name' => $track->artist?->name,
                'album_id' => $track->album_id,
                'album_name' => $track->album?->name,
                'duration' => $track->duration,
                'artwork' => LibraryItemArtwork::forImages($track->album?->images),
            ])->values(),
        ]);
    }

    /** Favorite albums and artists, most recently favorited first. */
    public function favorites(Request $request): JsonResponse
    {
        $hidden = $this->hiddenSources($request);

        $albums = LibrarySources::albums(Album::query()->whereNotNull('favorited_at')->with('artist'), $hidden)
            ->orderByDesc('favorited_at')->orderByDesc('id')->get();

        $artists = $this->artistItems(LibrarySources::artists(Artist::query()->whereNotNull('favorited_at'), $hidden), $hidden)
            ->orderByDesc('favorited_at')->orderByDesc('artists.id')->get();
        $this->preloadArtwork(albums: $albums, artists: $artists);

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

    private function setFavorite(Request $request, Album|Artist $model): bool
    {
        $request->validate(['favorite' => ['required', 'boolean']]);
        $favorite = $request->boolean('favorite');

        return $model->setFavorite($favorite);
    }

    /**
     * Reads the processed-artwork cache entries of everything a page shows in one round trip
     * (LibraryItemArtwork::preload), so the items cost no cache read each.
     *
     * @param  iterable<int, Album|null>  $albums
     * @param  iterable<int, Artist>  $artists
     * @param  iterable<int, array{url: string}|null>  $choices  PlaylistArtwork::choose()
     */
    private function preloadArtwork(iterable $albums = [], iterable $artists = [], iterable $choices = []): void
    {
        $urls = LibraryItemArtwork::urlsOfAlbums($albums);
        foreach ($artists as $artist) {
            $urls = [...$urls, ...LibraryItemArtwork::urlsOfAlbums([$artist->coverAlbum()])];
        }
        foreach ($choices as $choice) {
            $urls[] = $choice['url'] ?? null;
        }

        LibraryItemArtwork::preload($urls);
    }

    /** Eager loads what artistItem() reads: album count, and albums for the cover. */
    private function artistItems(Builder $query, array $hidden = []): Builder
    {
        return $query
            ->withCount(['albums' => fn ($q) => LibrarySources::albums($q, $hidden)])
            ->with(['albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')]);
    }

    /**
     * The sources hidden for this request: the scope (`?scope=local|streaming|all`,
     * default the leading source, see docs/api/library.md) plus what the
     * requesting client hides (`?client={api_token}`). Radio is not part
     * of the library, so it has no effect here.
     */
    private function hiddenSources(Request $request): array
    {
        $request->validate([
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        return LibrarySources::hiddenForRequest($this->client($request), $request->query('scope'));
    }

    /** What the requesting client hides (`?client={api_token}`), without any scope. */
    private function clientHidden(Request $request): array
    {
        $request->validate(['client' => ['nullable', 'string', 'max:100']]);

        return LibrarySources::hiddenFor($this->client($request));
    }

    private function client(Request $request): ?Client
    {
        return $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;
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

    /** @param array<int, array{url: string, sources: array}> $artwork PlaylistArtwork::choose() */
    private function playlistItem(Playlist $playlist, array $artwork): array
    {
        return [
            'id' => $playlist->id,
            'name' => $playlist->name,
            'track_count' => (int) $playlist->tracks_count,
            'owner' => $this->owner($playlist),
            'last_played_at' => $playlist->last_played_at?->toIso8601String(),
            'artwork' => isset($artwork[$playlist->id])
                ? LibraryItemArtwork::forUrl($artwork[$playlist->id]['url'], $artwork[$playlist->id]['sources'])
                : null,
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
