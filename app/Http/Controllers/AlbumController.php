<?php

namespace App\Http\Controllers;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Models\Device;
use App\Models\Media\Album;
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
        $album->load(['artist', 'tracks' => fn ($q) => $q->withCount('plays')->with(['metadata' => fn ($q) => $q->whereIn('key', ['dlna_url', 'lyrics_plain'])])->orderByDesc('plays_count')]);

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

    public function play(Request $request, Album $album, Device $device)
    {
        $tracks = $album->tracks()
            ->orderBy('id') // best-effort album order; no track_number column exists
            ->with(['metadata' => fn ($q) => $q->where('key', 'dlna_url')])
            ->get();

        if ($request->boolean('shuffle')) {
            $tracks = $tracks->shuffle();
        }

        $playableCount = $tracks->filter(fn ($t) => (bool) $t->getDlnaUrl())->count();

        if ($playableCount === 0) {
            return back()->with('error', "\"{$album->name}\" has no playable tracks.");
        }

        try {
            $driver = $device->driver;

            if (!($driver instanceof LibraryPlaybackInterface)) {
                return back()->with('error', "{$device->device_name} does not support library playback.");
            }

            $driver->playLibraryTracks($tracks);
        } catch (\Throwable $e) {
            return back()->with('error', "Could not play \"{$album->name}\" on {$device->device_name}: {$e->getMessage()}");
        }

        $message = $playableCount < $tracks->count()
            ? "Playing {$playableCount} of {$tracks->count()} tracks from \"{$album->name}\" on {$device->device_name}."
            : "Playing \"{$album->name}\" on {$device->device_name}.";

        return back()->with('success', $message);
    }
}
