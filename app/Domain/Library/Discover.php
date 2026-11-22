<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Catalogue\CatalogueItems;
use App\Domain\Profile\Profile;
use App\Domain\Similar\Embeddings;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Discover (workstream 4, docs/frontend/discover.md): the shelves of `GET /api/library/discover` and of the hub page
 * `/library/discover`, as plain data: a list of sections `{key, title, description, kind, items}` where every item has a
 * `reason` ("Favorite, last played 14 months ago"). A new shelf is a new section, so a client that lays out sections by
 * `kind` (album, artist, track, catalogue, mode) needs no release for it.
 *
 * What a section is made of:
 *  - the rediscover shelves (`Rediscover`): play history and favorites only,
 *  - `new_releases`: the catalogue's new and upcoming albums of your artists (`CatalogueItems::newReleases`), left out for a
 *    client token like the rest of the catalogue and where the profile has no catalogue,
 *  - `mixes_well` (Full): tracks that mix well with the analysed track played last (`MixesWith`),
 *  - `listening_modes` (Full): the presets with enough analysed tracks (`ListeningModes`),
 *  - `random_albums`: a few albums at random, a different pick for another `seed`.
 * A section with nothing in it is left out, so a small library answers a short list.
 */
final class Discover
{
    public const NEW_RELEASES = 'new_releases';

    public const MIXES_WELL = 'mixes_well';

    public const SIMILAR_TRACKS = 'similar_tracks';

    public const LISTENING_MODES = 'listening_modes';

    public const RANDOM_ALBUMS = 'random_albums';

    /**
     * Every section key in shelf order: new releases first, then the rediscover shelves, then the picks.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::NEW_RELEASES, DailyMix::KEY, ...Rediscover::keys(), self::MIXES_WELL, self::SIMILAR_TRACKS, self::LISTENING_MODES, self::RANDOM_ALBUMS];
    }

    /**
     * @param  list<string>  $hidden  sources hidden for the request (LibrarySources::hiddenForRequest)
     * @param  list<string>|null  $only  section keys to build (null = all); an unknown key is ignored
     * @param  int|null  $limit  items per section (default `discover.items`, at most `discover.max_items`)
     * @param  bool  $catalogue  false for a client token: its catalogue sections are left out
     * @param  int|null  $seed  random picks: another seed, another pick (null = a fresh one every time)
     * @return list<array{key: string, title: string, description: string, kind: string, items: list<array<string, mixed>>}>
     */
    public static function sections(array $hidden, ?array $only = null, ?int $limit = null, bool $catalogue = true, ?int $seed = null, ?CarbonInterface $now = null): array
    {
        $limit = max(1, min((int) ($limit ?? config('discover.items')), (int) config('discover.max_items')));
        $now = CarbonImmutable::instance($now ?? now());
        $wanted = fn (string $key) => $only === null || in_array($key, $only, true);

        $sections = [];

        if ($wanted(self::NEW_RELEASES) && $catalogue && Profile::allows('catalogue')) {
            $sections[] = self::newReleases($limit, $now);
        }

        if ($wanted(DailyMix::KEY)) {
            $sections[] = self::dailyMix($hidden, $now);
        }

        foreach (Rediscover::sections() as $key => $section) {
            if ($wanted($key)) {
                $sections[] = self::rediscover($key, $section, $hidden, $limit, $now);
            }
        }

        if ($wanted(self::MIXES_WELL) && Profile::allows('analysis')) {
            $sections[] = self::mixesWell($hidden, $limit, $now);
        }

        if ($wanted(self::SIMILAR_TRACKS) && Profile::allows('analysis') && Embeddings::any()) {
            $sections[] = self::similarTracks($hidden, $limit, $now);
        }

        if ($wanted(self::LISTENING_MODES) && Profile::allows('analysis')) {
            $sections[] = self::listeningModes();
        }

        if ($wanted(self::RANDOM_ALBUMS)) {
            $sections[] = self::randomAlbums($hidden, $limit, $seed);
        }

        return array_values(array_filter($sections, fn (?array $section) => $section !== null && $section['items'] !== []));
    }

    /** Section key => title, in shelf order (the hub's section chips). */
    public static function titles(): array
    {
        return [
            self::NEW_RELEASES => 'New from your artists',
            DailyMix::KEY => 'Your daily mix',
            ...array_map(fn (array $s) => $s['title'], Rediscover::sections()),
            self::MIXES_WELL => 'Mixes well with what you just played',
            self::SIMILAR_TRACKS => 'Because you played something',
            self::LISTENING_MODES => 'Listening modes',
            self::RANDOM_ALBUMS => 'Picked for you',
        ];
    }

    private static function section(string $key, string $description, string $kind, array $items, ?string $title = null): array
    {
        return ['key' => $key, 'title' => $title ?? self::titles()[$key], 'description' => $description, 'kind' => $kind, 'items' => $items];
    }

    /**
     * @param  array{title: string, description: string, kind: string}  $section
     */
    private static function rediscover(string $key, array $section, array $hidden, int $limit, CarbonImmutable $now): array
    {
        $rows = Rediscover::select($key, $hidden, $limit, $now);

        $items = match ($section['kind']) {
            'artist' => self::artistItems($rows, $hidden, $now, $key),
            'track' => self::trackItems($rows, $now, $key),
            default => self::albumItems($rows, $now, $key),
        };

        return self::section($key, $section['description'], $section['kind'], $items);
    }

    /** @param  list<array{id: int, favorite: bool, play_days: int, plays: int, last_played_at: ?string}>  $rows */
    private static function albumItems(array $rows, CarbonImmutable $now, string $key): array
    {
        $albums = Album::query()->with('artist')->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($albums));

        $items = [];
        foreach ($rows as $row) {
            if ($album = $albums->get($row['id'])) {
                $items[] = ApiItems::album($album) + self::facts($row) + ['reason' => self::reason($key, $row, $now)];
            }
        }

        return $items;
    }

    /** @param  list<array{id: int, favorite: bool, play_days: int, plays: int, last_played_at: ?string}>  $rows */
    private static function artistItems(array $rows, array $hidden, CarbonImmutable $now, string $key): array
    {
        $artists = Artist::query()
            ->withCount(['albums' => fn ($q) => LibrarySources::albums($q, $hidden)])
            ->with(['metadata' => fn ($q) => $q->whereIn('key', Artist::PHOTO_METADATA), 'albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');
        LibraryItemArtwork::preload($artists->map(fn (Artist $artist) => $artist->avatarUrl()));

        $items = [];
        foreach ($rows as $row) {
            if ($artist = $artists->get($row['id'])) {
                $items[] = ApiItems::artist($artist) + self::facts($row) + ['reason' => self::reason($key, $row, $now)];
            }
        }

        return $items;
    }

    /** @param  list<array{id: int, favorite: bool, play_days: int, plays: int, last_played_at: ?string}>  $rows */
    private static function trackItems(array $rows, CarbonImmutable $now, string $key): array
    {
        $tracks = Track::query()->with(['artist', 'album'])->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()));

        $items = [];
        foreach ($rows as $row) {
            if ($track = $tracks->get($row['id'])) {
                $items[] = ApiItems::track($track) + ['favorite' => $row['favorite'], 'plays' => $row['plays'], 'last_played_at' => self::iso($row['last_played_at'])]
                    + ['reason' => $key === Rediscover::DEEP_CUTS ? self::deepCutReason($track) : self::reason($key, $row, $now)];
            }
        }

        return $items;
    }

    /** What an album or artist item says about its plays: the days it was played and the last play (null when never). */
    private static function facts(array $row): array
    {
        return ['play_days' => $row['play_days'], 'last_played_at' => self::iso($row['last_played_at'])];
    }

    /**
     * The reason an item is on a shelf, in words: "Favorite, last played 1 year ago", "Played on 9 days, last 2 years ago",
     * "Played on 22 Nov 2024 (1 year ago)". Never a number the client has to explain.
     */
    private static function reason(string $key, array $row, CarbonImmutable $now): string
    {
        $last = $row['last_played_at'] === null ? null : CarbonImmutable::parse($row['last_played_at']);
        $ago = $last?->diffForHumans($now, CarbonInterface::DIFF_RELATIVE_TO_NOW);

        if ($key === Rediscover::BURIED_TREASURE) {
            return 'In your library for '.CarbonImmutable::parse($row['ref_at'])->diffForHumans($now, CarbonInterface::DIFF_ABSOLUTE).', never played';
        }

        if ($key === Rediscover::RELEASED_ON_THIS_DAY) {
            $date = CarbonImmutable::parse($row['ref_at']);
            $years = max(1, $now->year - $date->year);

            return 'Released '.$years.' '.($years === 1 ? 'year' : 'years').' ago, on '.$date->format('j M Y');
        }

        if ($key === Rediscover::ON_THIS_DAY) {
            return 'Played on '.$last->format('j M Y').' ('.$ago.')';
        }

        $parts = [];
        if ($row['favorite']) {
            $parts[] = 'Favorite';
        }
        if ($key === Rediscover::FORGOTTEN_TRACKS) {
            if ($row['plays'] > 0) {
                $parts[] = 'played '.$row['plays'].' '.($row['plays'] === 1 ? 'time' : 'times');
            }
        } elseif ($key !== Rediscover::NOT_PLAYED_IN_A_YEAR && $row['play_days'] > 0) {
            $parts[] = 'played on '.$row['play_days'].' '.($row['play_days'] === 1 ? 'day' : 'days');
        }
        $parts[] = $last === null ? 'not played yet' : 'last played '.$ago;

        return ucfirst(implode(', ', $parts));
    }

    private static function deepCutReason(Track $track): string
    {
        return 'Never played'.($track->album ? ', from '.$track->album->name : '');
    }

    private static function iso(?string $at): ?string
    {
        return $at === null ? null : CarbonImmutable::parse($at)->toIso8601String();
    }

    /** New and upcoming albums and EPs of your artists that the library lacks (the CatalogueItem plus `release_date` and a reason). */
    private static function newReleases(int $limit, CarbonImmutable $now): array
    {
        $items = array_map(function (array $item) use ($now) {
            $date = CarbonImmutable::parse($item['release_date']);

            return $item + ['reason' => $date->isAfter($now) ? 'Coming '.$date->format('j M') : 'Released '.$date->format('j M Y')];
        }, CatalogueItems::newReleases((int) config('discover.new_release_days'), null, $limit));

        return self::section(self::NEW_RELEASES, 'Albums and EPs of your artists that are out or coming and not in your library yet.', 'catalogue', $items);
    }

    /** The listening modes the library can serve, as items `{key, name, description, icon, track_count, reason}`. */
    private static function listeningModes(): array
    {
        $items = array_values(array_map(fn (array $mode) => $mode + ['reason' => $mode['description']], ListeningModes::available()));

        return self::section(self::LISTENING_MODES, 'Ready-made selections of your analysed tracks for a situation.', 'mode', $items);
    }

    /**
     * A few albums at random: one query for the ids of the scope (kept until the library changes), the pick in memory, one query
     * for the albums. The same `$seed` picks the same albums.
     */
    private static function randomAlbums(array $hidden, int $limit, ?int $seed): array
    {
        $ids = LibraryCache::remember('discover:album-ids:'.md5(implode(',', $hidden)), fn () => LibrarySources::albums(Album::query()->whereHas('tracks'), $hidden)->pluck('albums.id')->all());
        // A seed makes the pick repeatable (Collection::shuffle takes none); without one it is a fresh pick.
        $randomizer = $seed === null ? new Randomizer : new Randomizer(new Mt19937($seed));
        $picked = array_slice($randomizer->shuffleArray($ids), 0, $limit);

        $albums = Album::query()->with('artist')->whereIn('id', $picked)->get()->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($albums));

        $items = [];
        foreach ($picked as $id) {
            if ($album = $albums->get($id)) {
                $items[] = ApiItems::album($album) + ['reason' => 'Picked at random'];
            }
        }

        return self::section(self::RANDOM_ALBUMS, 'A few albums of your library at random.', 'album', $items);
    }

    /**
     * Tracks that mix well with the analysed own-file track you played last (within `mix_seed_days`): the existing `MixesWith`
     * over the audio analysis, closest first. Null without such a play. The selection is kept `cache_seconds`.
     */
    private static function mixesWell(array $hidden, int $limit, CarbonImmutable $now): ?array
    {
        $seedId = self::mixSeed($now);
        $seed = $seedId === null ? null : Track::query()->with('artist')->find($seedId);
        $source = $seed === null ? null : (new MixesWith($seed, $hidden))->source();
        if ($seed === null || $source === null) {
            return null;
        }

        $matches = Cache::remember('discover:mixes:'.$seed->id.':'.md5(implode(',', $hidden)).':'.$limit, max(1, (int) config('discover.cache_seconds')),
            fn () => (new MixesWith($seed, $hidden))->matches()->take($limit)->values()->all());
        $tracks = Track::query()->with(['artist', 'album'])->whereIn('id', array_column($matches, 'track_id'))->get()->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()));

        $what = $seed->name.($seed->artist ? ' by '.$seed->artist->name : '').' ('.$source['camelot'].', '.round($source['bpm']).' BPM)';
        $items = [];
        foreach ($matches as $match) {
            if ($track = $tracks->get($match['track_id'])) {
                $items[] = ApiItems::track($track) + [
                    'bpm' => $match['bpm'],
                    'camelot' => $match['camelot'],
                    'key_relation' => $match['key_relation'],
                    'tempo_relation' => $match['tempo_relation'],
                    'tempo_diff_percent' => round($match['tempo_diff'] * 100, 1),
                    'reason' => 'Mixes well with '.$what,
                ];
            }
        }

        return self::section(self::MIXES_WELL, 'Tracks with a similar tempo and a compatible key.', 'track', $items, 'Mixes well with '.$seed->name)
            + ['seed' => ['id' => $seed->id, 'name' => $seed->name, 'artist_name' => $seed->artist?->name, 'bpm' => $source['bpm'], 'camelot' => $source['camelot']]];
    }

    /** The daily mix as a track shelf; each item says why it is in it (`DailyMix`). Null when the library has nothing to bring back. */
    private static function dailyMix(array $hidden, CarbonImmutable $now): ?array
    {
        $picks = DailyMix::picks($hidden, $now);
        if ($picks === []) {
            return null;
        }

        $tracks = Track::query()->with(['artist', 'album'])->whereIn('id', array_column($picks, 'track_id'))->get()->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()));

        $items = [];
        foreach ($picks as $pick) {
            if ($track = $tracks->get($pick['track_id'])) {
                $items[] = ApiItems::track($track) + ['reason' => $pick['reason']];
            }
        }

        return self::section(DailyMix::KEY, 'Songs you loved, deep cuts and albums you never played, the same all day and new tomorrow.', 'track', $items);
    }

    /** Tracks that sound like the one you played last (the sound embedding, `SimilarTracks`). Null without such a play. */
    private static function similarTracks(array $hidden, int $limit, CarbonImmutable $now): ?array
    {
        $id = Play::query()
            ->join('track_files', fn ($join) => $join->on('track_files.track_id', '=', 'plays.track_id')->where('track_files.is_primary', true))
            ->join('file_embeddings', 'file_embeddings.file_id', '=', 'track_files.file_id')
            ->where('plays.played_at', '>=', $now->subDays(max(1, (int) config('discover.mix_seed_days')))->toDateTimeString())
            ->orderByDesc('plays.played_at')->orderByDesc('plays.id')
            ->value('plays.track_id');
        $seed = $id === null ? null : Track::query()->with('artist')->find($id);
        if ($seed === null) {
            return null;
        }

        return self::section(self::SIMILAR_TRACKS, 'Songs that sound like the one you played last.', 'track', (new SimilarTracks($seed, $hidden))->items($limit), 'Because you played '.$seed->name)
            + ['seed' => ['id' => $seed->id, 'name' => $seed->name, 'artist_name' => $seed->artist?->name]];
    }

    /** The analysed own-file track played last within `mix_seed_days`, or null. */
    private static function mixSeed(CarbonImmutable $now): ?int
    {
        $id = Play::query()
            ->join('track_files', fn ($join) => $join->on('track_files.track_id', '=', 'plays.track_id')->where('track_files.is_primary', true))
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->where('file_analyses.bpm', '>', 0)
            ->whereNotNull('file_analyses.key')
            ->where('plays.played_at', '>=', $now->subDays(max(1, (int) config('discover.mix_seed_days')))->toDateTimeString())
            ->orderByDesc('plays.played_at')->orderByDesc('plays.id')
            ->value('plays.track_id');

        return $id === null ? null : (int) $id;
    }
}
