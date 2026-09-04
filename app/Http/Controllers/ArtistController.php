<?php

namespace App\Http\Controllers;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Play;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
    public function index()
    {
        $artists = Artist::query()
            ->where(fn ($q) => $q->whereHas('plays')->orWhere('source', 'dlna'))
            ->withCount('plays')
            ->with(['albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->paginate(50);

        return view('artists.index', compact('artists'));
    }

    public function show(Artist $artist)
    {
        $artist->load(['albums.tracks', 'tracks.album']);

        $playableDevices = Device::libraryCapable();

        $totalPlays = $artist->plays()->count();

        $totalSeconds = Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id))
            ->whereNotNull('ended_at')
            ->get(['played_at', 'ended_at'])
            ->sum(fn ($p) => $p->played_at->diffInSeconds($p->ended_at));

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
        ));
    }

    public function play(Request $request, Artist $artist, Device $device)
    {
        $tracks = $artist->tracks()
            ->orderBy('album_id')->orderBy('id') // grouped by album, best-effort order within
            ->with(['metadata' => fn ($q) => $q->where('key', 'dlna_url')])
            ->get();

        if ($request->boolean('shuffle')) {
            $tracks = $tracks->shuffle();
        }

        $playableCount = $tracks->filter(fn ($t) => (bool) $t->getDlnaUrl())->count();

        if ($playableCount === 0) {
            return back()->with('error', "\"{$artist->name}\" has no playable tracks.");
        }

        try {
            $driver = $device->driver;

            if (!($driver instanceof LibraryPlaybackInterface)) {
                return back()->with('error', "{$device->device_name} does not support library playback.");
            }

            $driver->playLibraryTracks($tracks);
        } catch (\Throwable $e) {
            return back()->with('error', "Could not play \"{$artist->name}\" on {$device->device_name}: {$e->getMessage()}");
        }

        $message = $playableCount < $tracks->count()
            ? "Playing {$playableCount} of {$tracks->count()} tracks by \"{$artist->name}\" on {$device->device_name}."
            : "Playing \"{$artist->name}\" on {$device->device_name}.";

        return back()->with('success', $message);
    }
}
