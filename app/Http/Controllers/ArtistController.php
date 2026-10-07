<?php

namespace App\Http\Controllers;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibraryRemover;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Play;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
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

    public function show(Artist $artist)
    {
        $artist->load(['albums.tracks.metadata' => fn ($q) => $q->where('key', 'dlna_url'), 'tracks.album']);

        $playableDevices = Device::libraryCapable();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();

        $totalPlays = $artist->plays()->count();

        $totalSeconds = Play::secondsListened(Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id)));

        $topTracks = $artist->tracks()
            ->whereHas('plays')
            ->withCount('plays')
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        $recentPlays = Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id))
            ->with(['track.album', 'device', 'radioStation'])
            ->orderByDesc('played_at')
            ->limit(20)
            ->get();

        return view('artists.show', compact(
            'artist',
            'totalPlays',
            'totalSeconds',
            'topTracks',
            'recentPlays',
            'playableDevices',
            'spotifyConnected',
        ));
    }

    public function play(Request $request, Artist $artist, Device $device, LibraryPlayback $library)
    {
        try {
            $result = $library->playArtist($device, $artist, $request->boolean('shuffle'));
        } catch (NotPlayableException) {
            return back()->with('error', "\"{$artist->name}\" has no tracks {$device->device_name} can play.");
        } catch (PlaybackFailedException $e) {
            return back()->with('error', "Could not play \"{$artist->name}\" on {$device->device_name}: {$e->getMessage()}");
        }

        $message = $result->playable < $result->total
            ? "Playing {$result->playable} of {$result->total} tracks by \"{$artist->name}\" on {$device->device_name}."
            : "Playing \"{$artist->name}\" on {$device->device_name}.";

        return back()->with('success', $message);
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
        $artist->update(['favorited_at' => $artist->favorited_at ? null : now()]);

        return back();
    }
}
