<?php

namespace App\Domain\HomeAssistant;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Profile\Profile;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Support\Facades\DB;

/** The numbers behind the Home Assistant stats sensors; library figures only where the profile has a library. */
final class StatsSnapshot
{
    public const TOP = 10;

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $out = [
            'plays_today' => Play::where('played_at', '>=', now()->startOfDay())->count(),
            'plays_7d' => Play::where('played_at', '>=', now()->subDays(7))->count(),
            'plays_total' => Play::count(),
            'devices_playing' => Device::pluck('id')->filter(fn ($id) => DeviceCache::getState($id) === State::Playing)->count(),
        ];

        if (Profile::allows('library')) {
            $since = now()->subDays(30);
            $out += [
                'library_artists' => Artist::count(),
                'library_albums' => Album::count(),
                'library_tracks' => Track::count(),
                'favorites' => Album::whereNotNull('favorited_at')->count() + Artist::whereNotNull('favorited_at')->count(),
                'top_artists_30d' => $this->top('artists', 'artist_id', $since),
                'top_albums_30d' => $this->top('albums', 'album_id', $since),
                'top_tracks_30d' => $this->top('tracks', 'track_id', $since),
            ];
            foreach (['artist' => 'top_artists_30d', 'album' => 'top_albums_30d', 'track' => 'top_tracks_30d'] as $key => $list) {
                $out["top_{$key}_30d"] = $out[$list][0]['name'] ?? null;
            }
        }

        return $out;
    }

    /** @return list<array{name: string, plays: int}> */
    private function top(string $table, string $column, \DateTimeInterface $since): array
    {
        // plays has track_id only; albums and artists come through the track.
        $join = $column === 'track_id' ? 'plays.track_id' : "tracks.{$column}";
        $query = DB::table('plays')->where('plays.played_at', '>=', $since)->whereNotNull('plays.track_id');
        if ($column !== 'track_id') {
            $query->join('tracks', 'tracks.id', '=', 'plays.track_id');
        }

        return $query->join($table, "{$table}.id", '=', $join)
            ->groupBy("{$table}.id", "{$table}.name")->orderByRaw('COUNT(*) DESC')->orderBy("{$table}.id")->limit(self::TOP)
            ->selectRaw("{$table}.name as name, COUNT(*) as plays")->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'plays' => (int) $r->plays])->all();
    }
}
