<?php

namespace App\Domain\Library;

use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Media\Track;
use App\Models\TrackFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the Library page shows of the enrichment (genres, moods, tempo, ratings, Last.fm similar artists,
 * how complete it all is), for what a scope shows (`$hidden` as for LibrarySources). The expensive parts are
 * cached for a few minutes per scope, so a visit costs one cache read.
 */
final class LibraryOverview
{
    private const TTL = 600;

    /** Tempo buckets (BPM, lower bound inclusive, upper exclusive) for browsing by tempo. */
    public const TEMPOS = ['slow' => [0, 90], 'steady' => [90, 125], 'fast' => [125, 1000]];

    /** @return Builder<Album> */
    private static function albums(array $hidden): Builder
    {
        return LibrarySources::albums(Album::query(), $hidden);
    }

    /**
     * The genres most artists of the scope have, with those counts and the colours their covers share.
     *
     * @return Collection<int, object{id: int, name: string, slug: string, artists: int, colors: list<string>}>
     */
    public static function topGenres(array $hidden, int $limit = 14): Collection
    {
        return Cache::remember('library:genres:'.self::key($hidden).":{$limit}", self::TTL, function () use ($hidden, $limit) {
            $artistIds = LibrarySources::artists(Artist::query(), $hidden)->select('artists.id');

            $genres = Genre::query()
                ->join('genreables', fn ($join) => $join->on('genreables.genre_id', '=', 'genres.id')
                    ->where('genreables.genreable_type', (new Artist)->getMorphClass()))
                ->whereIn('genreables.genreable_id', $artistIds)
                ->groupBy('genres.id', 'genres.name', 'genres.slug')
                ->orderByRaw('count(*) desc')->orderBy('genres.name')
                ->limit($limit)
                ->get(['genres.id', 'genres.name', 'genres.slug', DB::raw('count(*) as artists')]);

            $colors = GenreColors::forGenres($genres->pluck('id')->all(), $hidden);

            return $genres->map(fn ($g) => (object) [
                'id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'artists' => (int) $g->artists, 'colors' => $colors[$g->id] ?? [],
            ]);
        });
    }

    /**
     * How many albums of the scope each of these genres has (the album's own or its artist's: whereGenre()), by genre id.
     *
     * @param  Collection<int, object{id: int, slug: string}>  $genres  topGenres()
     * @return array<int, int>
     */
    public static function genreAlbumCounts(array $hidden, Collection $genres): array
    {
        return Cache::remember('library:genre-albums:'.self::key($hidden).':'.md5($genres->pluck('id')->join(',')), self::TTL, function () use ($hidden, $genres) {
            return $genres->mapWithKeys(fn ($genre) => [
                $genre->id => self::whereGenre(self::albums($hidden), $genre->slug)->count(),
            ])->all();
        });
    }

    /**
     * Totals and completeness of the scope.
     *
     * @return array{artists: int, albums: int, tracks: int, hours: float, decades: array<string, int>, coverage: list<array{label: string, have: int, of: int}>}
     */
    public static function glance(array $hidden): array
    {
        return Cache::remember('library:glance:'.self::key($hidden), self::TTL, function () use ($hidden) {
            $albums = self::albums($hidden);
            $tracks = LibrarySources::tracks(Track::query(), $hidden);
            $artists = LibrarySources::artists(Artist::query(), $hidden);

            $albumCount = (clone $albums)->count();
            $artistCount = (clone $artists)->count();

            $decades = (clone $albums)->whereNotNull('albums.released_at')->pluck('albums.released_at')
                ->map(fn ($date) => intdiv((int) substr((string) $date, 0, 4), 10) * 10)
                ->filter(fn ($decade) => $decade >= 1900)->countBy()->sortKeys()
                ->mapWithKeys(fn ($count, $decade) => [$decade.'s' => $count])->all();

            $coverage = [
                ['label' => 'Albums with a cover', 'have' => (clone $albums)->whereNotNull('albums.images')->where('albums.images', '!=', '[]')->count(), 'of' => $albumCount],
                ['label' => 'Artists with genres', 'have' => (clone $artists)->whereExists(fn ($q) => $q->selectRaw('1')->from('genreables')
                    ->where('genreables.genreable_type', (new Artist)->getMorphClass())->whereColumn('genreables.genreable_id', 'artists.id'))->count(), 'of' => $artistCount],
                ['label' => 'Albums with release details', 'have' => (clone $albums)->whereExists(fn ($q) => self::albumMeta($q, ['release_type']))->count(), 'of' => $albumCount],
            ];

            $files = TrackFile::query()->where('is_primary', true)->where('role', TrackFile::ORIGINAL)->count();
            if ($files > 0) {
                $coverage[] = ['label' => 'Own files analysed', 'have' => FileAnalysis::query()->whereNotNull('loudness_lufs')->count(), 'of' => $files];
            }

            return [
                'artists' => $artistCount,
                'albums' => $albumCount,
                'tracks' => (clone $tracks)->count(),
                'hours' => round((int) (clone $tracks)->sum('tracks.duration') / 3600, 1),
                'decades' => $decades,
                'coverage' => $coverage,
            ];
        });
    }

    /**
     * Artists of the scope that Last.fm calls similar to the ones played most, and not played much themselves.
     *
     * @param  Collection<int, Artist>  $topArtists
     * @return Collection<int, Artist>
     */
    public static function similarArtists(array $hidden, Collection $topArtists, int $limit = 8): Collection
    {
        $names = $topArtists->take(6)->flatMap(fn (Artist $artist) => $artist->similarArtists())->unique()->values();

        if ($names->isEmpty()) {
            return collect();
        }

        return LibrarySources::artists(Artist::query(), $hidden)
            ->whereIn('artists.name', $names)
            ->whereNotIn('artists.id', $topArtists->pluck('id'))
            ->withCount(['albums', 'plays'])
            ->with(['metadata' => fn ($q) => $q->whereIn('key', Artist::PHOTO_METADATA), 'albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->limit($limit)
            ->get();
    }

    /**
     * How many albums of the scope have an analysed track in each mood / tempo bucket.
     *
     * @return array{moods: array<string, int>, tempos: array<string, int>}
     */
    public static function soundBuckets(array $hidden): array
    {
        return Cache::remember('library:sound:'.self::key($hidden), self::TTL, function () use ($hidden) {
            $rows = LibrarySources::tracks(Track::query(), $hidden)
                ->join('track_files', fn ($join) => $join->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
                ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
                ->whereNotNull('tracks.album_id')
                ->get(['tracks.album_id', 'file_analyses.bpm', 'file_analyses.mood_tags']);

            $moods = [];
            $tempos = [];

            foreach ($rows as $row) {
                foreach (array_column(json_decode($row->mood_tags ?? '[]', true) ?: [], 'name') as $mood) {
                    $moods[$mood][$row->album_id] = true;
                }
                if ($row->bpm !== null) {
                    foreach (self::TEMPOS as $name => [$from, $to]) {
                        if ($row->bpm >= $from && $row->bpm < $to) {
                            $tempos[$name][$row->album_id] = true;
                        }
                    }
                }
            }

            return [
                'moods' => collect($moods)->map(fn ($albums) => count($albums))->sortDesc()->all(),
                'tempos' => collect($tempos)->map(fn ($albums) => count($albums))->all(),
            ];
        });
    }

    /**
     * The best rated albums of the scope (TheAudioDB's score, 0–10).
     *
     * @return Builder<Album>
     */
    public static function topRated(array $hidden, float $minimum = 8.0): Builder
    {
        return self::orderByMeta(self::albums($hidden)
            ->whereExists(fn ($q) => self::albumMeta($q, ['rating'])->whereRaw('CAST(metadata.value AS DECIMAL(10,2)) >= ?', [$minimum])), 'rating');
    }

    /** Orders albums by a numeric metadata value, highest first; albums without it last. */
    public static function orderByMeta(Builder $albums, string $key): Builder
    {
        $wrap = fn (string $column) => DB::getQueryGrammar()->wrap($column);

        return $albums->orderByRaw(
            '(select CAST(m.value AS DECIMAL(12,2)) from metadata m where m.metadatable_type = ? and m.metadatable_id = albums.id and '.$wrap('m.key').' = ? limit 1) is null',
            [(new Album)->getMorphClass(), $key],
        )->orderByRaw(
            '(select CAST(m.value AS DECIMAL(12,2)) from metadata m where m.metadatable_type = ? and m.metadatable_id = albums.id and '.$wrap('m.key').' = ? limit 1) desc',
            [(new Album)->getMorphClass(), $key],
        );
    }

    /** Albums with the genre (by slug) themselves or through their artist. */
    public static function whereGenre(Builder $albums, string $slug): Builder
    {
        $genre = Genre::query()->where('slug', $slug)->value('id');

        return $albums->where(function (Builder $q) use ($genre) {
            foreach ([['albums.id', Album::class], ['albums.artist_id', Artist::class]] as [$column, $type]) {
                $q->orWhereExists(fn ($s) => $s->selectRaw('1')->from('genreables')
                    ->where('genreables.genre_id', $genre ?? 0)
                    ->where('genreables.genreable_type', (new $type)->getMorphClass())
                    ->whereColumn('genreables.genreable_id', $column));
            }
        });
    }

    /** Albums with an analysed track of this mood. */
    public static function whereMood(Builder $albums, string $mood): Builder
    {
        return $albums->whereExists(fn ($s) => self::analysedTracks($s)->where('file_analyses.mood_tags', 'like', '%"name":"'.str_replace(['%', '_', '"'], '', $mood).'"%'));
    }

    /** Albums with an analysed track in a tempo bucket (a key of TEMPOS). */
    public static function whereTempo(Builder $albums, string $tempo): Builder
    {
        [$from, $to] = self::TEMPOS[$tempo] ?? [0, 0];

        return $albums->whereExists(fn ($s) => self::analysedTracks($s)->where('file_analyses.bpm', '>=', $from)->where('file_analyses.bpm', '<', $to));
    }

    /** Analysed primary files of the tracks of the album in the outer query. */
    private static function analysedTracks($query)
    {
        return $query->selectRaw('1')->from('tracks')
            ->join('track_files', fn ($join) => $join->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->whereColumn('tracks.album_id', 'albums.id');
    }

    /** A metadata row of the album in the outer query with one of $keys. */
    public static function albumMeta($query, array $keys)
    {
        return $query->selectRaw('1')->from('metadata')
            ->where('metadata.metadatable_type', (new Album)->getMorphClass())
            ->whereColumn('metadata.metadatable_id', 'albums.id')
            ->whereIn('metadata.key', $keys);
    }

    private static function key(array $hidden): string
    {
        sort($hidden);

        return md5(json_encode($hidden));
    }
}
