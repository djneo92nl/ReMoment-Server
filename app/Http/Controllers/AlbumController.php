<?php

namespace App\Http\Controllers;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibraryRemover;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Models\Play;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AlbumController extends Controller
{
    public function index(Request $request)
    {
        $sort = in_array($request->query('sort'), ['plays', 'recent', 'name', 'artist', 'year'], true)
            ? $request->query('sort')
            : 'recent';
        $search = trim((string) $request->query('q'));
        $favorites = $request->boolean('fav');
        $scope = LeadingSource::forRequest($request);

        $albums = LibrarySources::albums(Album::query(), LeadingSource::hidden($scope))
            ->whereHas('tracks')
            ->with('artist')
            ->withSourceFlags()
            ->withCount('plays')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('albums.name', 'like', "%{$search}%")
                ->orWhereHas('artist', fn ($a) => $a->where('name', 'like', "%{$search}%"))))
            ->when($favorites, fn ($q) => $q->whereNotNull('albums.favorited_at'))
            ->when($sort === 'plays', fn ($q) => $q->orderByDesc('plays_count')->orderByDesc('albums.created_at'))
            ->when($sort === 'recent', fn ($q) => $q->orderByDesc('albums.created_at')->orderByDesc('albums.id'))
            ->when($sort === 'year', fn ($q) => $q->orderByDesc('albums.released_at')->orderBy('albums.name'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('albums.name'))
            ->when($sort === 'artist', fn ($q) => $q
                ->join('artists', 'artists.id', '=', 'albums.artist_id')
                ->orderByRaw(\App\Models\Media\Artist::SORT_NAME_SQL)->orderBy('albums.name')
                ->select('albums.*'))
            ->paginate(96)
            ->withQueryString();

        return view('albums.index', compact('albums', 'sort', 'scope', 'search', 'favorites'));
    }

    public function show(Album $album)
    {
        $album->load(['artist', 'tracks' => fn ($q) => $q->withCount('plays')->with(['genreRelation', 'metadata' => fn ($q) => $q->whereIn('key', [...Track::DISPLAY_METADATA, 'external_id'])])->orderByDesc('plays_count')]);

        // Album order (disc, then track number), tracks without a position last by plays.
        $album->setRelation('tracks', $album->tracks->sortBy(fn ($t) => [
            (int) ($t->metaValue('disc_number') ?: 1),
            (int) ($t->metaValue('track_number') ?: 9999),
            -$t->plays_count,
        ])->values());

        $totalPlays = $album->plays()->count();

        $totalSeconds = Play::whereHas('track', fn ($q) => $q->where('album_id', $album->id))
            ->whereNotNull('ended_at')
            ->get(['played_at', 'ended_at'])
            ->sum(fn ($p) => $p->played_at->diffInSeconds($p->ended_at));

        $recentPlays = Play::whereHas('track', fn ($q) => $q->where('album_id', $album->id))
            ->with(['track', 'device', 'radioStation'])
            ->orderByDesc('played_at')
            ->limit(20)
            ->get();

        $playableDevices = Device::libraryCapable();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();

        return view('albums.show', compact(
            'album',
            'totalPlays',
            'totalSeconds',
            'recentPlays',
            'playableDevices',
            'spotifyConnected',
        ));
    }

    public function play(Request $request, Album $album, Device $device, LibraryPlayback $library)
    {
        try {
            $result = $library->playAlbum($device, $album, shuffle: $request->boolean('shuffle'));
        } catch (NotPlayableException) {
            return back()->with('error', "\"{$album->name}\" has no tracks {$device->device_name} can play.");
        } catch (PlaybackFailedException $e) {
            return back()->with('error', "Could not play \"{$album->name}\" on {$device->device_name}: {$e->getMessage()}");
        }

        $message = $result->playable < $result->total
            ? "Playing {$result->playable} of {$result->total} tracks from \"{$album->name}\" on {$device->device_name}."
            : "Playing \"{$album->name}\" on {$device->device_name}.";

        return back()->with('success', $message);
    }

    /** Adds the album's missing tracks from Spotify, whatever source the album came from. */
    public function fill(Album $album, SpotifyTokenService $spotify, SpotifyLibraryImporter $importer)
    {
        if (!$spotify->isConnected()) {
            return back()->with('error', 'Connect Spotify first.');
        }

        try {
            $spotifyAlbumId = $importer->findAlbumId($album);

            if ($spotifyAlbumId === null) {
                return back()->with('error', "Could not find \"{$album->name}\" on Spotify.");
            }

            $before = $album->tracks()->count();
            $importer->importAlbum($spotifyAlbumId, $album);
            $added = $album->tracks()->count() - $before;
        } catch (\Throwable $e) {
            return back()->with('error', "Could not fill \"{$album->name}\" from Spotify: {$e->getMessage()}");
        }

        return back()->with('success', $added > 0
            ? "Added {$added} ".Str::plural('track', $added)." to \"{$album->name}\" from Spotify."
            : "\"{$album->name}\" is already complete.");
    }

    /** Removes an album that has no DLNA tracks (admin): its tracks, and plays turn into text plays. */
    public function destroy(Album $album)
    {
        $artist = $album->artist;
        $name = $album->name;

        if (LibraryRemover::album($album) === null) {
            return back()->with('error', "\"{$name}\" has tracks from the DLNA library and can't be removed here.");
        }

        return ($artist !== null && \App\Models\Media\Artist::whereKey($artist->id)->exists()
            ? redirect()->route('artists.show', $artist)
            : redirect()->route('albums.index'))->with('success', "Removed \"{$name}\" from the library.");
    }

    /** Removes one track that isn't on DLNA (admin). */
    public function destroyTrack(Track $track)
    {
        $name = $track->name;
        $albumId = $track->album_id;

        if (!LibraryRemover::track($track)) {
            return back()->with('error', "\"{$name}\" is in the DLNA library and can't be removed here.");
        }

        $album = $albumId ? Album::find($albumId) : null;

        return ($album ? redirect()->route('albums.show', $album) : redirect()->route('albums.index'))
            ->with('success', "Removed \"{$name}\" from the library.");
    }

    public function favorite(Request $request, Album $album)
    {
        $album->update(['favorited_at' => $album->favorited_at ? null : now()]);

        return back();
    }
}
