<?php

namespace App\Http\Controllers;

use App\Models\Media\Artist;
use App\Models\Media\Album;
use App\Models\Device;
use App\Models\Play;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        $topArtists = Artist::whereHas('plays')
            ->withCount('plays')
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        $topAlbums = Album::whereHas('plays')
            ->withCount('plays')
            ->with('artist')
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        $deviceStats = Device::all()->map(function ($device) {
            $plays = Play::where('device_id', $device->id)->count();
            return ['device' => $device, 'plays' => $plays];
        })->sortByDesc('plays')->filter(fn ($d) => $d['plays'] > 0)->values();

        // Plays by hour of day and day of week — computed in PHP for DB portability
        $playsByHour = array_fill(0, 24, 0);
        $playsByDay = array_fill(0, 7, 0);

        Play::query()->get(['played_at'])->each(function ($play) use (&$playsByHour, &$playsByDay) {
            $playsByHour[(int) $play->played_at->format('G')]++;
            $playsByDay[(int) $play->played_at->format('w')]++;
        });

        $totalPlays = Play::count();
        $totalTracks = \App\Models\Media\Track::count();
        $totalArtists = Artist::count();

        return view('stats.index', compact(
            'topArtists',
            'topAlbums',
            'deviceStats',
            'playsByHour',
            'playsByDay',
            'totalPlays',
            'totalTracks',
            'totalArtists',
        ));
    }
}
