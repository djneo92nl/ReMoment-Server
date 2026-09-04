<?php

namespace App\Http\Controllers;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Play;

class LibraryController extends Controller
{
    public function index()
    {
        $recentAlbums = Album::query()
            ->where(fn ($q) => $q->whereHas('plays')->orWhere('source', 'dlna'))
            ->with('artist')
            ->latest()
            ->limit(12)
            ->get();

        $recentPlays = Play::whereNotNull('track_id')
            ->with(['track.album', 'track.artist', 'device'])
            ->orderByDesc('played_at')
            ->limit(10)
            ->get();

        $topArtists = Artist::query()
            ->whereHas('plays')
            ->withCount('plays')
            ->with(['albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        return view('library.index', compact('recentAlbums', 'recentPlays', 'topArtists'));
    }
}
