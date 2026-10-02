<?php

namespace App\Http\Controllers;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Http\Request;

class AlbumController extends Controller
{
    public function index(Request $request)
    {
        $sort = in_array($request->query('sort'), ['plays', 'recent', 'name', 'artist'], true)
            ? $request->query('sort')
            : 'plays';

        $albums = Album::query()
            ->where(fn ($q) => $q->whereHas('plays')->orWhere('albums.source', 'dlna'))
            ->with('artist')
            ->withCount('plays')
            ->when($sort === 'plays', fn ($q) => $q->orderByDesc('plays_count')->orderByDesc('created_at'))
            ->when($sort === 'recent', fn ($q) => $q->orderByDesc('created_at'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($sort === 'artist', fn ($q) => $q
                ->join('artists', 'artists.id', '=', 'albums.artist_id')
                ->orderBy('artists.name')->orderBy('albums.name')
                ->select('albums.*'))
            ->paginate(50)
            ->withQueryString();

        return view('albums.index', compact('albums', 'sort'));
    }

    public function show(Album $album)
    {
        $album->load(['artist', 'tracks' => fn ($q) => $q->withCount('plays')->with(['genreRelation', 'metadata' => fn ($q) => $q->whereIn('key', Track::DISPLAY_METADATA)])->orderByDesc('plays_count')]);

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

        return view('albums.show', compact(
            'album',
            'totalPlays',
            'totalSeconds',
            'recentPlays',
            'playableDevices',
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

    public function favorite(Request $request, Album $album)
    {
        $album->update(['favorited_at' => $album->favorited_at ? null : now()]);

        return back();
    }
}
