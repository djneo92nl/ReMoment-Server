<?php

namespace App\Domain\Radio;

use App\Domain\Library\Normalizer;
use App\Models\Media\Artist;
use App\Models\RadioPlay;
use App\Models\RadioStation;
use App\Models\RadioStationMeta;

/** What to listen to next: stations like the favourites, and where the artists you play are heard on the radio. */
class RadioSuggestions
{
    public function __construct(private readonly RadioBrowser $browser) {}

    /**
     * Stations of the genres of the favourite stations (the most common first), trending in the directory, that are not
     * in the library yet. Empty without favourites that have a genre.
     *
     * @return list<array<string, mixed>> radio-browser results, as {@see RadioBrowser::search()}
     */
    public function similarToFavourites(int $limit = 12): array
    {
        $genres = RadioStation::query()->whereNotNull('favorited_at')->whereNotNull('genre')->pluck('genre')
            ->countBy()->sortDesc()->keys()->take(2);

        if ($genres->isEmpty()) {
            return [];
        }

        $owned = RadioStationMeta::whereIn('key', ['url', 'radio_browser_uuid'])->pluck('value')->flip();

        return $genres->flatMap(fn (string $genre) => $this->browser->browse('trending', mb_strtolower($genre), null, 25))
            ->reject(fn (array $r) => $owned->has($r['url']) || ($r['uuid'] && $owned->has($r['uuid'])))
            ->unique('url')->values()->take($limit)->all();
    }

    /**
     * Artists you play most, with the stations they were heard on lately (from the radio play log).
     *
     * @return list<array{artist: string, station_id: int, station: string, count: int}> most heard first
     */
    public function yourArtistsOnTheRadio(int $limit = 8): array
    {
        $top = Artist::query()->whereHas('plays')->withCount('plays')->orderByDesc('plays_count')->limit(60)->get()
            ->mapWithKeys(fn (Artist $a) => [Normalizer::artist($a->name) => $a->name]);

        if ($top->isEmpty()) {
            return [];
        }

        $found = [];

        RadioPlay::query()->whereNotNull('artist')->where('started_at', '>=', now()->subDays(30))
            ->with('station:id,name')->latest('started_at')->limit(5000)->get()
            ->each(function (RadioPlay $play) use ($top, &$found) {
                $name = $top[Normalizer::artist($play->artist)] ?? null;

                if ($name === null || $play->station === null) {
                    return;
                }

                $key = $name.'|'.$play->radio_station_id;
                $found[$key] ??= ['artist' => $name, 'station_id' => $play->radio_station_id, 'station' => $play->station->name, 'count' => 0];
                $found[$key]['count']++;
            });

        return collect($found)->sortByDesc('count')->values()->take($limit)->all();
    }
}
