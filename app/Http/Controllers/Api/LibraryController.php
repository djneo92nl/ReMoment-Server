<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Health\LibraryReports;
use App\Domain\Library\GenreColors;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\ListeningModes;
use App\Domain\Library\PlaylistCoverage;
use App\Domain\Library\PlaylistMode;
use App\Domain\Library\RandomAlbum;
use App\Domain\Library\RecentItems;
use App\Domain\Library\SmartPlaylist\SmartPlaylistDescriber;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /** Album name for sorting: lower case (a leading "The " is not ignored for albums). */
    private const ALBUM_SORT_NAME = 'LOWER(albums.name)';

    /** Artists with albums, paged: `sort` is name (default) or plays (most played first, in the scope). */
    public function artists(Request $request): JsonResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string', 'max:200'],
            'letter' => ['nullable', 'string', 'regex:/^[A-Za-z#]$/'],
            'sort' => ['nullable', 'in:name,plays'],
        ]);
        $byPlays = $request->query('sort') === 'plays';
        $hidden = $this->hiddenSources($request);
        $offset = $this->decodeCursor($request->query('cursor'));

        if (!$byPlays) {
            $offset = $this->letterOffset(
                LibrarySources::artists(Artist::query()->whereHas('albums'), $hidden),
                self::ARTIST_SORT_NAME, $request, $offset,
            );
        }

        $query = $this->artistItems(LibrarySources::artists(Artist::query()->whereHas('albums'), $hidden), $hidden);

        if ($byPlays) {
            // Plays of the artist's albums' visible tracks in the scope, summed once in SQL and joined;
            // artists without plays count 0. Ties fall back to name then id, so the offset cursor is stable.
            $plays = Play::query()
                ->join('tracks', 'tracks.id', '=', 'plays.track_id')
                ->join('albums', 'albums.id', '=', 'tracks.album_id');
            LibrarySources::tracks($plays, $hidden);
            $plays->selectRaw('albums.artist_id, COUNT(plays.id) AS plays_total')->groupBy('albums.artist_id');

            // No select() here: it would replace the albums_count subselect that artistItems() added.
            $query->leftJoinSub($plays, 'artist_plays', 'artist_plays.artist_id', '=', 'artists.id')
                ->orderByRaw('COALESCE(artist_plays.plays_total, 0) DESC');
        }

        $artists = $query
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
            'letter' => ['nullable', 'string', 'regex:/^[A-Za-z#]$/'],
            'sort' => ['nullable', 'in:name,artist,year,added,plays'],
        ]);
        $hidden = $this->hiddenSources($request);
        $offset = $this->decodeCursor($request->query('cursor'));
        $sort = $request->query('sort', 'name');

        $query = LibrarySources::albums(Album::query(), $hidden)->whereHas('tracks')->with('artist');

        match ($sort) {
            'artist' => $query->join('artists', 'artists.id', '=', 'albums.artist_id')
                ->orderByRaw(self::ARTIST_SORT_NAME)->orderBy('albums.name')->select('albums.*'),
            'year' => $query->orderByRaw('albums.released_at IS NULL')->orderByDesc('albums.released_at')->orderBy('albums.name'),
            'added' => $query->orderByDesc('albums.created_at')->orderByDesc('albums.id'),
            'plays' => $query->withCount('plays')->orderByDesc('plays_count')->orderByDesc('albums.created_at'),
            default => $query->orderByRaw(self::ALBUM_SORT_NAME)->orderBy('albums.name'),
        };

        // Jump to a letter in the active sort key (name or artist); other sorts ignore it.
        if ($sort === 'name' || $sort === 'artist') {
            $offset = $this->letterOffset($query, $sort === 'artist' ? self::ARTIST_SORT_NAME : self::ALBUM_SORT_NAME, $request, $offset);
        }

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

    /**
     * One random album with a visible track in the scope (docs/api/library.md). With `device_id`, an
     * album that device can play is preferred, else any. The pick is a random offset into the id
     * order (a count and a one-row query), never loading the albums.
     */
    public function randomAlbum(Request $request): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;

        $album = RandomAlbum::pick($this->hiddenSources($request), $device);

        abort_if($album === null, 404, 'No albums in this library.');
        $this->preloadArtwork(albums: [$album]);

        return response()->json(['data' => $this->albumItem($album)]);
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
            'details' => $artist->details() + [
                'fanart' => LibraryItemArtwork::fanart($artist->fanartUrl()),
                'gallery' => array_values(array_filter(array_map(LibraryItemArtwork::fanart(...), $artist->galleryUrls()))),
            ],
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
        $tracks->load(['genreRelation', 'analysis', 'metadata' => fn ($q) => $q->whereIn('key', array_unique([...LibraryPlayback::PLAYBACK_METADATA, ...Track::DISPLAY_METADATA]))]);
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

    /** The waveform of a track's own audio file: peak values 0–100, evenly spread over its length. */
    public function waveform(Track $track): JsonResponse
    {
        $points = $track->waveform();

        abort_if($points === null, 404, 'This track has no waveform (yet).');

        return response()->json(['points' => $points, 'duration' => $track->duration])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /** How many findings each library health report has; the findings themselves are on the web page. */
    public function health(): JsonResponse
    {
        $counts = LibraryReports::counts();

        return response()->json(['data' => array_values(array_map(
            fn (string $key, array $definition) => ['key' => $key, 'title' => $definition['title'], 'description' => $definition['description'], 'count' => $counts[$key] ?? 0],
            array_keys(LibraryReports::definitions()),
            LibraryReports::definitions(),
        ))]);
    }

    /** The listening modes (focus, party, …) the library has enough analysed tracks for; see docs/architecture/listening-modes.md. */
    public function listeningModes(): JsonResponse
    {
        return response()->json(['data' => array_values(ListeningModes::available())]);
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

        $colors = GenreColors::forGenres($genres->pluck('id')->all(), $hidden);

        return response()->json(['data' => $genres->map(fn (Genre $genre) => [
            'slug' => $genre->slug,
            'name' => $genre->name,
            'artist_count' => $genre->artists_count,
            'album_count' => $genre->albums_count,
            'colors' => $colors[$genre->id] ?? [],
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
            'colors' => GenreColors::forGenres([$genre->id], $hidden)[$genre->id] ?? [],
            'artists' => $artists->map(fn (Artist $artist) => $this->artistItem($artist))->values(),
            'albums' => $albums->map(fn (Album $album) => $this->albumItem($album))->values(),
        ]);
    }

    /**
     * Playlists with tracks, recently played first (Playlist::withTracksByRecency). In the Local scope
     * (`dlna` / `local`, also the default while Local leads) playlists of another library (Spotify) are
     * listed too when PlaylistCoverage::MIN_COVERAGE of their tracks exist in Local: `external: true`.
     */
    public function playlists(Request $request): JsonResponse
    {
        $request->validate(['cursor' => ['nullable', 'string', 'max:200']]);
        $offset = $this->decodeCursor($request->query('cursor'));
        $hidden = $this->hiddenSources($request);
        $localScope = in_array(LeadingSource::scope($request->query('scope')), ['local', PlaylistMode::LOCAL_LIBRARY], true);
        $clientHidden = $this->clientHidden($request);

        $query = Playlist::query();
        if (!$localScope) {
            LibrarySources::playlists($query, $hidden);
        } else {
            // Local playlists by their visible (Local) tracks; playlists of another library by their coverage instead.
            $query->where(function (Builder $q) use ($hidden, $clientHidden) {
                $q->where(fn (Builder $own) => LibrarySources::playlists(PlaylistCoverage::ownedByOtherLibrary($own, not: true), $hidden));
                if (!in_array(PlaylistMode::LOCAL_LIBRARY, $clientHidden, true)) {
                    $q->orWhere(fn (Builder $other) => PlaylistCoverage::ownedByOtherLibrary($other, $clientHidden)
                        ->whereIn('playlists.id', PlaylistCoverage::qualifyingIds()));
                }
            });
        }

        $playlists = $query
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
        // Local copies of the whole page in one aggregate query.
        $coverage = PlaylistCoverage::counts($playlists->filter(fn (Playlist $p) => PlaylistCoverage::applies($p))->pluck('id'));

        return response()->json([
            'data' => $playlists->map(fn (Playlist $playlist) => $this->playlistListItem($playlist, $artwork, $coverage[$playlist->id] ?? null, $localScope))->values(),
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + self::PLAYLISTS_PAGE_SIZE) : null,
        ]);
    }

    /**
     * A playlist with its tracks. `library` is the library the client browsed in; it decides the mode
     * the playlist would play in (PlaylistMode): `local` lists every track, those without a Local copy
     * as `missing_locally`.
     */
    public function playlist(Request $request, Playlist $playlist): JsonResponse
    {
        $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'library' => ['nullable', 'string', Rule::in(LibrarySources::libraryIds())],
        ]);
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;
        $mode = PlaylistMode::resolve($playlist, $request->query('library'));

        $hidden = $this->hiddenSources($request);
        $library = LibraryPlayback::playlistLibrary($playlist);
        if ($mode->isLocal()) {
            // Opened as a Local playlist, every track is shown: the missing ones are marked, not hidden.
            $hidden = [];
        } elseif ($library !== null) {
            // A playlist of a library lists what is visible in THAT library, not what the default (leading
            // library) scope shows: a Spotify playlist lists all its tracks. The client's own hidden sources still apply.
            $hidden = array_values(array_unique([...$this->clientHidden($request), ...LibrarySources::allBut($library)]));
        }

        $playlist->load(['metadata' => fn ($q) => $q->where('key', 'spotify_owner')]);
        $choice = PlaylistArtwork::choose(collect([$playlist]))[$playlist->id] ?? null;
        // The albums of the whole page in one query, their covers in one cache read.
        $tracks = LibraryPlayback::playlistTracks($playlist, self::PLAYLIST_TRACKS, $hidden)->load('album');
        $this->preloadArtwork(albums: $tracks->pluck('album'), choices: [$choice]);
        $localDevice = $device === null || LibrarySources::playableOn(PlaylistMode::LOCAL_LIBRARY, $device);
        $items = $tracks->map(function (Track $track) use ($playlist, $device, $mode, $localDevice) {
            $item = [
                'id' => $track->id,
                'name' => $track->name,
                'artist_name' => $track->artist?->name,
                'duration' => $track->duration,
                'artwork' => LibraryItemArtwork::forImages($track->album?->images),
            ];

            if ($mode->isLocal()) {
                $local = (bool) $track->getDlnaUrl();

                return $item + ['playable' => $local && $localDevice, 'missing_locally' => !$local];
            }

            return $item + ['playable' => LibraryPlayback::playlistTrackPlayable($playlist, $track, $device)];
        })->values();

        // Of a library playlist (or in local mode) the listed tracks are the playlist's: the count is exact when
        // all fit, else the visible total. Local and smart playlists keep counting every track.
        $ofLibrary = $mode->isLocal() || $library !== null;
        $trackCount = $ofLibrary
            ? ($items->count() < self::PLAYLIST_TRACKS ? $items->count() : LibrarySources::tracks($playlist->tracks(), $hidden)->count())
            : null;
        $counts = PlaylistCoverage::applies($playlist) ? (PlaylistCoverage::counts([$playlist->id])[$playlist->id] ?? ['total' => 0, 'local' => 0]) : null;
        $smart = $playlist->isSmart();

        return response()->json([
            'id' => $playlist->id,
            'name' => $playlist->name,
            'owner' => $this->owner($playlist),
            'last_played_at' => $playlist->last_played_at?->toIso8601String(),
            'artwork' => $choice === null ? null : LibraryItemArtwork::forUrl($choice['url'], $choice['sources']),
            'mode' => $mode->mode,
            'mode_library' => $mode->library,
            // A playlist of a library (Spotify) is of that library only, whatever its tracks' merged copies offer; in local mode it is Local.
            'sources' => $mode->isLocal() ? [PlaylistMode::LOCAL_LIBRARY] : ($library !== null ? [$library] : LibrarySources::ofTracks($tracks)),
            'track_count' => $trackCount ?? $counts['total'] ?? $playlist->tracks()->count(),
            'local_track_count' => $counts['local'] ?? null,
            'local_coverage' => $counts ? PlaylistCoverage::ratio($counts['local'], $counts['total']) : null,
            'smart' => $smart,
            'rules' => $smart ? SmartPlaylistDescriber::definition($playlist->rules) : null,
            'rules_summary' => $smart ? SmartPlaylistDescriber::summary($playlist->rules) : null,
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

        ['items' => $items, 'artwork' => $artwork] = RecentItems::get($this->clientHidden($request), $request->integer('limit', 30));
        $this->preloadArtwork(albums: $items->pluck('album')->filter(), choices: $artwork);

        return response()->json(['data' => $items->map(fn (array $item) => [
            'type' => $item['type'],
            'library' => $item['library'],
            'played_at' => $item['played_at'],
            'album' => $item['album'] ? $this->albumItem($item['album']) : null,
            'playlist' => $item['playlist'] ? $this->playlistItem($item['playlist'], $artwork) : null,
            'station' => $item['station']?->apiItem(),
        ])->values()]);
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

    /**
     * A playlist of the list: the item plus its library and, for a playlist of another library, how
     * much of it exists in Local (`track_count` is then all its tracks).
     *
     * @param  array<int, array{url: string, sources: array}>  $artwork
     * @param  array{total: int, local: int}|null  $counts  PlaylistCoverage::counts()
     * @param  bool  $localScope  listed in the Local scope: a playlist of another library is then `external`
     */
    private function playlistListItem(Playlist $playlist, array $artwork, ?array $counts, bool $localScope): array
    {
        $item = $this->playlistItem($playlist, $artwork);

        return array_merge($item, [
            'track_count' => $counts !== null ? $counts['total'] : $item['track_count'],
            'library' => LibraryPlayback::playlistLibrary($playlist),
            'external' => $counts !== null && $localScope,
            'local_track_count' => $counts['local'] ?? null,
            'local_coverage' => $counts !== null ? PlaylistCoverage::ratio($counts['local'], $counts['total']) : null,
        ]);
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

    /**
     * Jump to a letter (the clients' A-Z bar): the offset of the first row whose sort key (lower case SQL
     * expression) sorts at or after the letter; "#" is the start (digits and symbols sort before letters).
     * Only without a cursor: with one, paging continues from the cursor's offset. The count ignores ordering.
     */
    private function letterOffset(Builder $query, string $sortKeySql, Request $request, int $offset): int
    {
        $letter = $request->query('letter');

        if ($letter === null || $letter === '#' || $request->query('cursor') !== null) {
            return $offset;
        }

        return (clone $query)->reorder()->whereRaw($sortKeySql.' < ?', [strtolower($letter)])->count();
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
