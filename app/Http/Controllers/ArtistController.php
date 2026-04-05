<?php

namespace App\Http\Controllers;

use App\Models\Media\Artist;
use App\Models\Play;

class ArtistController extends Controller
{
    public function index()
    {
        $artists = Artist::query()
            ->whereHas('plays')
            ->withCount('plays')
            ->orderByDesc('plays_count')
            ->paginate(50);

        return view('artists.index', compact('artists'));
    }

    public function show(Artist $artist)
    {
        $artist->load(['albums.tracks', 'tracks.album']);

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
            ->with(['track.album', 'device'])
            ->orderByDesc('played_at')
            ->limit(20)
            ->get();

        return view('artists.show', compact(
            'artist',
            'totalPlays',
            'totalSeconds',
            'topTracks',
            'recentPlays',
        ));
    }
}
