<?php

namespace App\Http\Controllers;

use App\Models\Media\Album;
use App\Models\Play;

class AlbumController extends Controller
{
    public function show(Album $album)
    {
        $album->load(['artist', 'tracks' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')]);

        $totalPlays = $album->plays()->count();

        $totalSeconds = Play::whereHas('track', fn ($q) => $q->where('album_id', $album->id))
            ->whereNotNull('ended_at')
            ->get(['played_at', 'ended_at'])
            ->sum(fn ($p) => $p->played_at->diffInSeconds($p->ended_at));

        $recentPlays = Play::whereHas('track', fn ($q) => $q->where('album_id', $album->id))
            ->with(['track', 'device'])
            ->orderByDesc('played_at')
            ->limit(20)
            ->get();

        return view('albums.show', compact(
            'album',
            'totalPlays',
            'totalSeconds',
            'recentPlays',
        ));
    }
}
