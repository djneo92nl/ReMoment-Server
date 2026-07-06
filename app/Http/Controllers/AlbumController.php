<?php

namespace App\Http\Controllers;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Play;

class AlbumController extends Controller
{
    public function show(Album $album)
    {
        $album->load(['artist', 'tracks' => fn ($q) => $q->withCount('plays')->with(['metadata' => fn ($q) => $q->where('key', 'dlna_url')])->orderByDesc('plays_count')]);

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

        $playableDevices = Device::all()->filter(function ($d) {
            try {
                return $d->driver instanceof LibraryPlaybackInterface;
            } catch (\Throwable) {
                return false;
            }
        })->values();

        return view('albums.show', compact(
            'album',
            'totalPlays',
            'totalSeconds',
            'recentPlays',
            'playableDevices',
        ));
    }
}
