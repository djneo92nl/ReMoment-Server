<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

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

        $topTracks = Track::whereHas('plays')
            ->withCount('plays')
            ->with(['artist', 'album'])
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        $deviceStats = Device::all()->map(function ($device) {
            return ['device' => $device, 'plays' => Play::where('device_id', $device->id)->count()];
        })->sortByDesc('plays')->filter(fn ($d) => $d['plays'] > 0)->values();

        // Plays by hour of day
        $playsByHour = array_fill(0, 24, 0);
        $hourExpr = $isSqlite
            ? "CAST(strftime('%H', played_at) AS INTEGER) as hour"
            : 'HOUR(played_at) as hour';
        DB::table('plays')
            ->selectRaw("$hourExpr, COUNT(*) as count")
            ->whereNotNull('played_at')
            ->groupBy('hour')
            ->get()
            ->each(fn ($row) => $playsByHour[(int) $row->hour] = (int) $row->count);

        // Plays by day of week (0=Sunday … 6=Saturday)
        $playsByDay = array_fill(0, 7, 0);
        $dowExpr = $isSqlite
            ? "CAST(strftime('%w', played_at) AS INTEGER) as dow"
            : 'DAYOFWEEK(played_at) - 1 as dow';
        DB::table('plays')
            ->selectRaw("$dowExpr, COUNT(*) as count")
            ->whereNotNull('played_at')
            ->groupBy('dow')
            ->get()
            ->each(fn ($row) => $playsByDay[(int) $row->dow] = (int) $row->count);

        // Last 30 days trend
        $trendRaw = DB::table('plays')
            ->selectRaw('DATE(played_at) as day, COUNT(*) as count')
            ->where('played_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('count', 'day');

        $last30Days = collect();
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last30Days->put($date, (int) ($trendRaw[$date] ?? 0));
        }

        // Source distribution
        $nullLabel = $isSqlite ? "COALESCE(source_type, 'unknown')" : "COALESCE(source_type, 'unknown')";
        $sourceDistribution = DB::table('plays')
            ->selectRaw("$nullLabel as source_type, COUNT(*) as count")
            ->groupBy('source_type')
            ->orderByDesc('count')
            ->get();

        // Total listening time in seconds
        $secondsExpr = $isSqlite
            ? 'CAST((julianday(ended_at) - julianday(played_at)) * 86400 AS INTEGER)'
            : 'TIMESTAMPDIFF(SECOND, played_at, ended_at)';
        $listeningSeconds = (int) DB::table('plays')
            ->whereNotNull('ended_at')
            ->whereRaw('ended_at > played_at')
            ->selectRaw("SUM($secondsExpr) as seconds")
            ->value('seconds');

        $totalPlays = Play::count();
        $totalTracks = Track::count();
        $totalArtists = Artist::count();
        $skippedPlays = Play::where('skipped', true)->count();

        return view('stats.index', compact(
            'topArtists',
            'topAlbums',
            'topTracks',
            'deviceStats',
            'playsByHour',
            'playsByDay',
            'last30Days',
            'sourceDistribution',
            'listeningSeconds',
            'totalPlays',
            'totalTracks',
            'totalArtists',
            'skippedPlays',
        ));
    }
}
