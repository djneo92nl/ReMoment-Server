<?php

namespace App\Http\Controllers;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Play;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    public function index(Request $request)
    {
        $scope = LeadingSource::forRequest($request);
        $hidden = LeadingSource::hidden($scope);

        $recentAlbums = LibrarySources::albums(Album::query(), $hidden)
            ->whereHas('tracks')
            ->with('artist')
            ->withSourceFlags()
            ->latest()
            ->limit(18)
            ->get();

        $recentPlays = Play::where(fn ($q) => $q->whereNotNull('track_id')->orWhereNotNull('track_name'))
            ->with(['track.album', 'track.artist', 'device'])
            ->orderByDesc('played_at')
            ->limit(10)
            ->get();

        $topArtists = LibrarySources::artists(Artist::query(), $hidden)
            ->whereHas('plays')
            ->withCount('plays')
            ->with(['albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        return view('library.index', compact('recentAlbums', 'recentPlays', 'topArtists', 'scope'));
    }
}
