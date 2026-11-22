<?php

namespace App\Domain\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Rediscover (workstream 4, docs/frontend/discover.md): what you loved but have not played for a long time, and what
 * you played on this day in other years. Everything comes from the play history and the favourites: no external
 * calls and no table. Each shelf is one aggregate query over `plays` joined to the library, not a query per item;
 * the selection (ids and the numbers behind the reason) is kept `discover.cache_seconds`, the models are loaded
 * fresh by the caller with their relations.
 *
 * "Loved" is measured two ways: a favourite (the heart), and listening: an album or artist you played on several
 * different days, a track you played several times. Days, not plays, because an album played through once is a
 * dozen track plays but one evening. A play with no library track (a radio song, a text-only play) belongs to no
 * album and counts for nothing here. Thresholds are in config/discover.php.
 */
final class Rediscover
{
    public const FORGOTTEN_FAVORITES = 'forgotten_favorites';

    public const USED_TO_PLAY = 'used_to_play';

    public const FORGOTTEN_ARTISTS = 'forgotten_artists';

    public const FORGOTTEN_TRACKS = 'forgotten_tracks';

    public const ON_THIS_DAY = 'on_this_day';

    public const NOT_PLAYED_IN_A_YEAR = 'not_played_in_a_year';

    // The shelves that need no play history: what you own and never heard, and what came out on this day.
    public const BURIED_TREASURE = 'buried_treasure';

    public const DEEP_CUTS = 'deep_cuts';

    public const RELEASED_ON_THIS_DAY = 'released_on_this_day';

    /**
     * Section key => title, description and the kind of item. The order is the order of the shelves.
     *
     * @return array<string, array{title: string, description: string, kind: string}>
     */
    public static function sections(): array
    {
        return [
            self::FORGOTTEN_FAVORITES => ['title' => 'Forgotten favorites', 'kind' => 'album', 'description' => 'Albums you favorited and have not played for a while.'],
            self::USED_TO_PLAY => ['title' => 'You used to play these', 'kind' => 'album', 'description' => 'Albums you played on many days and then stopped.'],
            self::ON_THIS_DAY => ['title' => 'Around this day', 'kind' => 'album', 'description' => 'Albums you played around this date in earlier years.'],
            self::FORGOTTEN_ARTISTS => ['title' => 'Artists you left behind', 'kind' => 'artist', 'description' => 'Artists you loved and have not played for a while.'],
            self::FORGOTTEN_TRACKS => ['title' => 'Songs you loved', 'kind' => 'track', 'description' => 'Favorite or often played songs that have not been on for a while.'],
            self::NOT_PLAYED_IN_A_YEAR => ['title' => 'Not played in a year', 'kind' => 'album', 'description' => 'Albums you tried once and have not touched for a year.'],
            self::BURIED_TREASURE => ['title' => 'Buried treasure', 'kind' => 'album', 'description' => 'Albums in your library that you have never played.'],
            self::DEEP_CUTS => ['title' => 'Deep cuts', 'kind' => 'track', 'description' => 'Songs you never played, on albums you love.'],
            self::RELEASED_ON_THIS_DAY => ['title' => 'Released on this day', 'kind' => 'album', 'description' => 'Albums that came out around this date in an earlier year.'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::sections());
    }

    /**
     * The selection of one shelf: ids in shelf order, each with its facts (`favorite`, `play_days` or `plays`, `last_played_at`).
     * Cached briefly; `$now` is the clock of the tests.
     *
     * @param  list<string>  $hidden  sources hidden for the request (LibrarySources)
     * @return list<array{id: int, favorite: bool, play_days: int, plays: int, last_played_at: ?string}>
     */
    public static function select(string $key, array $hidden, int $limit, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $cacheKey = implode(':', ['discover', $key, md5(implode(',', $hidden)), $limit, $now->toDateString()]);

        return Cache::remember($cacheKey, max(1, (int) config('discover.cache_seconds')), fn () => match ($key) {
            self::FORGOTTEN_FAVORITES => self::forgottenFavorites($hidden, $limit, $now),
            self::USED_TO_PLAY => self::usedToPlay($hidden, $limit, $now),
            self::ON_THIS_DAY => self::onThisDay($hidden, $limit, $now),
            self::FORGOTTEN_ARTISTS => self::forgottenArtists($hidden, $limit, $now),
            self::FORGOTTEN_TRACKS => self::forgottenTracks($hidden, $limit, $now),
            self::NOT_PLAYED_IN_A_YEAR => self::notPlayedInAYear($hidden, $limit, $now),
            self::BURIED_TREASURE => self::buriedTreasure($hidden, $limit, $now),
            self::DEEP_CUTS => self::deepCuts($hidden, $limit, $now),
            self::RELEASED_ON_THIS_DAY => self::releasedOnThisDay($hidden, $limit, $now),
            default => [],
        });
    }

    /** Favorite albums with no play since `stale_days` (or none on record): the ones you played most first, never played last. */
    private static function forgottenFavorites(array $hidden, int $limit, CarbonImmutable $now): array
    {
        return self::rows(self::albums($hidden)
            ->whereNotNull('albums.favorited_at')
            ->where(self::staleSince($now, 'stale_days'))
            ->orderByRaw('pa.last_played_at IS NULL')->orderByRaw('COALESCE(pa.play_days, 0) DESC')->orderBy('pa.last_played_at')->orderBy('albums.id')
            ->limit($limit), 'albums.favorited_at');
    }

    /** Albums that are not favorites, played on at least `album_min_days` days, with no play since `stale_days`. */
    private static function usedToPlay(array $hidden, int $limit, CarbonImmutable $now): array
    {
        return self::rows(self::albums($hidden)
            ->whereNull('albums.favorited_at')
            ->where('pa.play_days', '>=', (int) config('discover.album_min_days'))
            ->where('pa.last_played_at', '<', self::cutoff($now, 'stale_days'))
            ->orderByDesc('pa.play_days')->orderBy('pa.last_played_at')->orderBy('albums.id')
            ->limit($limit));
    }

    /** Albums tried a few times (fewer than the "used to play" days), the last play at least `long_ago_days` ago. */
    private static function notPlayedInAYear(array $hidden, int $limit, CarbonImmutable $now): array
    {
        return self::rows(self::albums($hidden)
            ->whereNull('albums.favorited_at')
            ->where('pa.play_days', '<', (int) config('discover.album_min_days'))
            ->where('pa.last_played_at', '<', self::cutoff($now, 'long_ago_days'))
            ->orderByDesc('pa.play_days')->orderByDesc('pa.last_played_at')->orderBy('albums.id')
            ->limit($limit));
    }

    /**
     * Albums you played within `on_this_day_window` days of today's date in an earlier year (up to `on_this_day_years` back), most
     * play days first, then the latest. One range per year over `plays.played_at`, so the index answers it. `last_played_at` is the
     * latest of those plays, which the reason shows.
     */
    private static function onThisDay(array $hidden, int $limit, CarbonImmutable $now): array
    {
        $window = max(0, (int) config('discover.on_this_day_window'));
        $ranges = [];
        for ($ago = 1; $ago <= max(1, (int) config('discover.on_this_day_years')); $ago++) {
            // Feb 29 is Feb 28 in a year that has none (no overflow into March).
            $day = $now->subYearsNoOverflow($ago);
            $ranges[] = [$day->subDays($window)->startOfDay()->toDateTimeString(), $day->addDays($window)->endOfDay()->toDateTimeString()];
        }

        $plays = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereNotNull('tracks.album_id')
            ->where(function (Builder $q) use ($ranges) {
                foreach ($ranges as $range) {
                    $q->orWhereBetween('plays.played_at', $range);
                }
            })
            ->groupBy('tracks.album_id')
            ->selectRaw('tracks.album_id, COUNT(DISTINCT DATE(plays.played_at)) AS play_days, MAX(plays.played_at) AS last_played_at');

        return self::rows(LibrarySources::albums(Album::query()->whereHas('tracks'), $hidden)
            ->joinSub($plays, 'pa', 'pa.album_id', '=', 'albums.id')
            ->select('albums.id', 'pa.play_days', 'pa.last_played_at')
            ->orderByDesc('pa.play_days')->orderByDesc('pa.last_played_at')->orderBy('albums.id')
            ->limit($limit));
    }

    /** Artists (with a visible album) that are favorites or were played on `artist_min_days` days, with no play since `stale_days`. */
    private static function forgottenArtists(array $hidden, int $limit, CarbonImmutable $now): array
    {
        $plays = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereNotNull('tracks.artist_id')
            ->groupBy('tracks.artist_id')
            ->selectRaw('tracks.artist_id, COUNT(DISTINCT DATE(plays.played_at)) AS play_days, MAX(plays.played_at) AS last_played_at');

        return self::rows(LibrarySources::artists(Artist::query(), $hidden)
            ->leftJoinSub($plays, 'pa', 'pa.artist_id', '=', 'artists.id')
            ->select('artists.id', 'pa.play_days', 'pa.last_played_at')
            ->where(fn (Builder $q) => $q->whereNotNull('artists.favorited_at')->orWhere('pa.play_days', '>=', (int) config('discover.artist_min_days')))
            ->where(self::staleSince($now, 'stale_days'))
            ->orderByRaw('pa.last_played_at IS NULL')->orderByRaw('COALESCE(pa.play_days, 0) DESC')->orderBy('pa.last_played_at')->orderBy('artists.id')
            ->limit($limit), 'artists.favorited_at');
    }

    /** Tracks that are favorites or were played `track_min_plays` times, with no play since `stale_days`. `plays` of the result is the track's play count. */
    private static function forgottenTracks(array $hidden, int $limit, CarbonImmutable $now): array
    {
        $plays = Play::query()
            ->whereNotNull('plays.track_id')
            ->groupBy('plays.track_id')
            ->selectRaw('plays.track_id, COUNT(*) AS play_count, MAX(plays.played_at) AS last_played_at');

        return self::rows(LibrarySources::tracks(Track::query(), $hidden)
            ->leftJoinSub($plays, 'pt', 'pt.track_id', '=', 'tracks.id')
            ->select('tracks.id', 'pt.play_count', 'pt.last_played_at')
            ->where(fn (Builder $q) => $q->whereNotNull('tracks.favorited_at')->orWhere('pt.play_count', '>=', (int) config('discover.track_min_plays')))
            ->where(fn (Builder $q) => $q->whereNull('pt.last_played_at')->orWhere('pt.last_played_at', '<', self::cutoff($now, 'stale_days')))
            ->orderByRaw('pt.last_played_at IS NULL')->orderByRaw('COALESCE(pt.play_count, 0) DESC')->orderBy('pt.last_played_at')->orderBy('tracks.id')
            ->limit($limit), 'tracks.favorited_at');
    }

    /**
     * Albums owned for at least `buried_days` and never played (a left join: no play at all). Needs no history, so a new or imported
     * library has it. `ref_at` is when the album was added, which the reason shows. The pick is shuffled by the date.
     */
    private static function buriedTreasure(array $hidden, int $limit, CarbonImmutable $now): array
    {
        return self::dailyShuffle(self::rows(self::albums($hidden)
            ->whereNull('pa.play_days')
            ->where('albums.created_at', '<', self::cutoff($now, 'buried_days'))
            ->addSelect('albums.created_at as ref_at')
            ->orderBy('albums.id')
            ->limit((int) config('discover.pool'))), $limit, $now, self::BURIED_TREASURE);
    }

    /** Tracks never played on albums that are favorites or were played on `album_min_days` days: the songs you skipped past. */
    private static function deepCuts(array $hidden, int $limit, CarbonImmutable $now): array
    {
        $trackPlays = Play::query()->whereNotNull('plays.track_id')->groupBy('plays.track_id')->selectRaw('plays.track_id');
        $albumPlays = Play::query()
            ->join('tracks as t', 't.id', '=', 'plays.track_id')
            ->whereNotNull('t.album_id')
            ->groupBy('t.album_id')
            ->selectRaw('t.album_id, COUNT(DISTINCT DATE(plays.played_at)) AS play_days');

        return self::dailyShuffle(self::rows(LibrarySources::tracks(Track::query(), $hidden)
            ->join('albums', 'albums.id', '=', 'tracks.album_id')
            ->leftJoinSub($trackPlays, 'pt', 'pt.track_id', '=', 'tracks.id')
            ->leftJoinSub($albumPlays, 'pa', 'pa.album_id', '=', 'albums.id')
            ->whereNull('pt.track_id')
            ->where(fn (Builder $q) => $q->whereNotNull('albums.favorited_at')->orWhere('pa.play_days', '>=', (int) config('discover.album_min_days')))
            ->select('tracks.id')
            ->orderBy('tracks.id')
            ->limit((int) config('discover.pool'))), $limit, $now, self::DEEP_CUTS);
    }

    /**
     * Albums released within `released_window` days of today's date in an earlier year (up to `released_years` back), the most
     * recent first. One range per year. A tag often gives only the year or the month, which `ReleaseDate` stores as the 1st, so a
     * release on a 1st is left out: it would turn up on every 1st of a month for a day nobody knows.
     */
    private static function releasedOnThisDay(array $hidden, int $limit, CarbonImmutable $now): array
    {
        $window = max(0, (int) config('discover.released_window'));
        $ranges = [];
        for ($ago = 1; $ago <= max(1, (int) config('discover.released_years')); $ago++) {
            $day = $now->subYearsNoOverflow($ago);
            $ranges[] = [$day->subDays($window)->startOfDay()->toDateTimeString(), $day->addDays($window)->endOfDay()->toDateTimeString()];
        }

        $rows = self::rows(LibrarySources::albums(Album::query()->whereHas('tracks'), $hidden)
            ->where(function (Builder $q) use ($ranges) {
                foreach ($ranges as $range) {
                    $q->orWhereBetween('albums.released_at', $range);
                }
            })
            ->select('albums.id', 'albums.released_at as ref_at')
            ->orderByDesc('albums.released_at')->orderBy('albums.id')
            ->limit($limit * 3));

        return array_slice(array_values(array_filter($rows, fn (array $row) => substr((string) $row['ref_at'], 8, 2) !== '01')), 0, $limit);
    }

    /**
     * The same rows in an order that is fixed for a day and different tomorrow (a seeded shuffle of the date and the shelf), cut to
     * `$limit`. A shelf of things you never heard would otherwise show the same oldest albums until you play them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function dailyShuffle(array $rows, int $limit, CarbonInterface $now, string $salt): array
    {
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32($now->toDateString().$salt)));

        return array_slice($randomizer->shuffleArray($rows), 0, $limit);
    }

    /** Albums of the scope with their play days and last play (a left join: never played is null). */
    private static function albums(array $hidden): Builder
    {
        $plays = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereNotNull('tracks.album_id')
            ->groupBy('tracks.album_id')
            ->selectRaw('tracks.album_id, COUNT(DISTINCT DATE(plays.played_at)) AS play_days, MAX(plays.played_at) AS last_played_at');

        return LibrarySources::albums(Album::query()->whereHas('tracks'), $hidden)
            ->leftJoinSub($plays, 'pa', 'pa.album_id', '=', 'albums.id')
            ->select('albums.id', 'pa.play_days', 'pa.last_played_at');
    }

    /** Never played, or last played before the cutoff of `$setting` days. */
    private static function staleSince(CarbonImmutable $now, string $setting): \Closure
    {
        return fn (Builder $q) => $q->whereNull('pa.last_played_at')->orWhere('pa.last_played_at', '<', self::cutoff($now, $setting));
    }

    private static function cutoff(CarbonImmutable $now, string $setting): string
    {
        return $now->subDays(max(1, (int) config("discover.{$setting}")))->toDateTimeString();
    }

    /**
     * @param  string|null  $favoriteColumn  the column that says it is a favorite; without one the row is not
     * @return list<array{id: int, favorite: bool, play_days: int, plays: int, last_played_at: ?string, ref_at: ?string}>
     */
    private static function rows(Builder $query, ?string $favoriteColumn = null): array
    {
        if ($favoriteColumn !== null) {
            $query->addSelect($favoriteColumn.' as favorited_at');
        }

        return $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'favorite' => $favoriteColumn !== null && $row->favorited_at !== null,
            'play_days' => (int) $row->play_days,
            // Only a track row carries a play count of its own; an album or artist counts days.
            'plays' => (int) ($row->getAttributes()['play_count'] ?? 0),
            'last_played_at' => $row->last_played_at,
            // A date a shelf has instead of a play: when the album was added, or its release date.
            'ref_at' => $row->getAttributes()['ref_at'] ?? null,
        ])->all();
    }
}
