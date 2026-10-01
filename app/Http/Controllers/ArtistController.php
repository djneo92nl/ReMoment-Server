<?php

namespace App\Http\Controllers;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
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

    public function favorite(Artist $artist)
    {
        $artist->update(['favorited_at' => $artist->favorited_at ? null : now()]);

        return back();
    }
}
