<?php

namespace App\Http\Controllers;

use App\Domain\Library\AlbumPage;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibraryRemover;
use App\Domain\Library\LibrarySources;
use App\Http\Controllers\Concerns\FlashesLibraryPlayback;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Play;
use App\Services\SpotifyTokenService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
    use FlashesLibraryPlayback;

    public function index(Request $request)
    {
        $sort = in_array($request->query('sort'), ['name', 'plays', 'albums'], true) ? $request->query('sort') : 'name';
        $search = trim((string) $request->query('q'));
        $favorites = $request->boolean('fav');
        $genreSlug = (string) $request->query('genre');
        $letter = strtoupper((string) $request->query('letter'));
        $letter = preg_match('/^[A-Z#]$/', $letter) ? $letter : null;

        $scope = LeadingSource::forRequest($request);
        $hidden = LeadingSource::hidden($scope);
        $genre = $genreSlug !== '' ? Genre::where('slug', $genreSlug)->first() : null;

        // Everything but the letter: the A-Z bar counts what each letter would show.
        $base = LibrarySources::artists(Artist::query(), $hidden)
            ->whereHas('albums', fn ($q) => LibrarySources::albums($q, $hidden))
            ->when($search !== '', fn ($q) => $q->where('artists.name', 'like', "%{$search}%"))
            ->when($favorites, fn ($q) => $q->whereNotNull('artists.favorited_at'))
            ->when($genre, fn ($q) => $q->whereIn('artists.id', $genre->artists()->select('artists.id')));

        $letters = (clone $base)
            ->selectRaw('UPPER(SUBSTR('.Artist::SORT_NAME_SQL.', 1, 1)) as letter, COUNT(*) as total')
            ->groupBy('letter')
            ->pluck('total', 'letter')
            ->reduce(function ($carry, $total, $l) {
                $key = preg_match('/^[A-Z]$/', (string) $l) ? $l : '#';
                $carry[$key] = ($carry[$key] ?? 0) + $total;

                return $carry;
            }, []);
        ksort($letters);

        $artists = (clone $base)
            ->when($letter === '#', fn ($q) => $q->whereRaw('SUBSTR('.Artist::SORT_NAME_SQL.', 1, 1) NOT BETWEEN ? AND ?', ['a', 'z']))
            ->when($letter !== null && $letter !== '#', fn ($q) => $q->whereRaw('SUBSTR('.Artist::SORT_NAME_SQL.', 1, 1) = ?', [strtolower($letter)]))
            ->withCount(['plays', 'albums' => fn ($q) => LibrarySources::albums($q, $hidden)])
            ->with([
                'albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at'),
                'metadata' => fn ($q) => $q->whereIn('key', ['images', 'spotify_image']),
            ])
            ->when($sort === 'name', fn ($q) => $q->orderByRaw(Artist::SORT_NAME_SQL)->orderBy('artists.id'))
            ->when($sort === 'plays', fn ($q) => $q->orderByDesc('plays_count')->orderByRaw(Artist::SORT_NAME_SQL))
            ->when($sort === 'albums', fn ($q) => $q->orderByDesc('albums_count')->orderByRaw(Artist::SORT_NAME_SQL))
            ->paginate(120)
            ->withQueryString();

        $genres = Genre::query()->whereHas('artists')->orderBy('name')->get(['slug', 'name']);

        return view('artists.index', compact('artists', 'scope', 'sort', 'search', 'favorites', 'genre', 'genres', 'letter', 'letters'));
    }

    public function show(Request $request, Artist $artist)
    {
        $sourceMeta = fn ($q) => $q->whereIn('key', ['dlna_url', 'external_id']);
        $artist->load(['albums.metadata' => fn ($q) => $q->whereIn('key', Album::CARD_METADATA), 'albums.tracks.metadata' => $sourceMeta, 'tracks.metadata' => $sourceMeta, 'tracks.album']);

        $playableDevices = Device::libraryCapable();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();

        // Like the album page: one source at a time, streaming is never merged with local. Spotify's side is always
        // one click away while it is connected.
        $sources = array_values(array_intersect(LibrarySources::libraryIds(), LibrarySources::ofTracks($artist->tracks)));
        if ($spotifyConnected && !in_array('spotify', $sources, true)) {
            $sources[] = 'spotify';
        }
        $requested = $request->query('source');
        $source = AlbumPage::selectSource($sources, is_string($requested) ? $requested : $artist->metaValue('default_source'), LeadingSource::forRequest($request));
        $inSource = fn ($track) => $source === null || in_array($source, $track->sources(), true);

        // The Spotify side shows what Spotify has (all its albums, the popular tracks), not the library's copies: the artist's Spotify id is all it needs.
        $spotifyArtistId = $source === 'spotify' ? app(\App\Domain\Library\SpotifyCatalog::class)->spotifyArtistId($artist) : null;

        $albums = $source === 'spotify' ? collect() : $artist->albums->filter(fn ($album) => $album->tracks->contains($inSource))->values();
        $trackCount = $source === 'spotify' ? 0 : $artist->tracks->filter($inSource)->count();

        $totalPlays = $artist->plays()->count();

        $totalSeconds = Play::secondsListened(Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id)));

        $topTracks = $source === 'spotify' ? collect() : $artist->tracks()
            ->whereHas('plays')
            ->when($source !== null, fn ($q) => LibrarySources::availableFrom($q, $source))
            ->withCount('plays')
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        $recentPlays = Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id))
            ->with(['track.album', 'device', 'radioStation'])
            ->orderByDesc('played_at')
            ->limit(20)
            ->get();

        // The shelf cards show album and play counts.
        $similarArtists = (new EloquentCollection($artist->similarArtistModels()->values()->all()))->loadCount(['albums', 'plays']);

        return view('artists.show', compact(
            'artist',
            'similarArtists',
            'sources',
            'source',
            'albums',
            'trackCount',
            'totalPlays',
            'totalSeconds',
            'topTracks',
            'recentPlays',
            'playableDevices',
            'spotifyConnected',
            'spotifyArtistId',
        ));
    }

    public function play(Request $request, Artist $artist, Device $device, LibraryPlayback $library)
    {
        $source = $request->validate(['source' => ['nullable', 'in:files,dlna,spotify']])['source'] ?? null;

        return $this->flashPlayback(fn () => $library->playArtist($device, $artist, $request->boolean('shuffle'), $source), $device, $artist->name);
    }

    /** Removes an artist and everything of theirs that isn't on DLNA (admin); an empty artist just goes. */
    public function destroy(Artist $artist)
    {
        $name = $artist->name;

        if (LibraryRemover::artist($artist) === null) {
            return back()->with('error', "\"{$name}\" has tracks from the DLNA library and can't be removed here.");
        }

        return redirect()->route('artists.index')->with('success', "Removed \"{$name}\" from the library.");
    }

    public function favorite(Artist $artist)
    {
        $artist->toggleFavorite();

        return back();
    }
}
