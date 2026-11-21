<?php

namespace App\Domain\Health;

use App\Domain\Artwork\CoverFacts;
use App\Domain\Artwork\LibraryArtwork;
use App\Models\Media\Album;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports on the health of the own files: what the audio analysis found wrong (suspected transcodes, clipping,
 * damaged files), files and albums that need attention (duplicates, lossy files, incomplete albums, files without a
 * track, files gone missing). Read only: each report is a query, so a count is always current to the last analysis.
 * Counts are cached for a few minutes; a report's rows are not.
 *
 * Only present, non-hidden files count. Add a report with one entry in `definitions()`, a `count` and a `rows` case.
 */
final class LibraryReports
{
    public const CACHE_KEY = 'library-health:counts';

    private const TTL = 600;

    /** Lossless extensions (the same set the quality stage uses); anything else is lossy. */
    private const LOSSLESS = ['flac', 'wav', 'aif', 'aiff', 'ape', 'wv', 'tta', 'alac'];

    /** @return array<string, array{title: string, description: string}> */
    public static function definitions(): array
    {
        return [
            'transcodes' => ['title' => 'Suspected transcodes', 'description' => 'Lossless files whose spectrum ends where a lossy encoder cuts it. A hint, not proof: a naturally band-limited recording looks the same.'],
            'damaged' => ['title' => 'Damaged files', 'description' => 'The decoder complained, or the file decodes shorter than its tags say.'],
            'clipping' => ['title' => 'Clipping', 'description' => 'Flat, repeated peaks: the audio was clipped when it was mastered or converted.'],
            'duplicates' => ['title' => 'Duplicate recordings', 'description' => 'The same recording (by audio fingerprint) stored more than once. Keep the best file.'],
            'lossy' => ['title' => 'Lossy files', 'description' => 'Tracks you only have as MP3, AAC or similar, the worst spectrum first: candidates to replace with a lossless copy.'],
            'incomplete' => ['title' => 'Incomplete albums', 'description' => 'Albums with fewer tracks than the MusicBrainz release lists. It may be a different edition (deluxe, regional).'],
            'unlinked' => ['title' => 'Files without a track', 'description' => 'Files in storage that no track plays.'],
            'missing' => ['title' => 'Missing files', 'description' => 'Files that were indexed and are no longer on disk.'],
            'covers' => ['title' => 'Cover problems', 'description' => 'Albums with no cover, a tiny or non-square cover, or an embedded cover that differs from the one in use. Needs the cover size, recorded when a cover is processed (artwork:measure-covers fills older ones).'],
            'suggestions' => ['title' => 'Tag and cover suggestions', 'description' => 'Files whose tags disagree with the recording the audio fingerprint found (title, artist, a missing year), albums with a poor cover that MusicBrainz has a front image for, and inconsistent track numbers within an album. Nothing is applied by itself: accept writes the tag into the file or fetches the cover, dismiss hides it.'],
        ];
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, fn () => array_combine(
            array_keys(self::definitions()),
            array_map(self::count(...), array_keys(self::definitions())),
        ));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    public static function count(string $key): int
    {
        return match ($key) {
            'duplicates' => DB::query()->fromSub(self::duplicateGroups(), 'groups')->count(),
            'incomplete' => self::incomplete()->count(),
            'covers' => count(self::coverProblems()),
            'suggestions' => Suggestions::count(),
            default => self::query($key)->count(),
        };
    }

    /**
     * One row per finding.
     *
     * @return Collection<int, array{title: string, detail: string, url: ?string}>
     */
    public static function rows(string $key, int $limit = 200): Collection
    {
        return match ($key) {
            'duplicates' => self::duplicateRows($limit),
            'incomplete' => self::incomplete()->orderByRaw('(have - total) asc')->limit($limit)->get()->map(fn ($r) => [
                'title' => "{$r->artist} · {$r->album}",
                'detail' => "{$r->have} of {$r->total} tracks",
                'url' => route('albums.show', $r->album_id),
            ]),
            'covers' => collect(array_slice(self::coverProblems(), 0, $limit))->map(fn ($r) => [
                'title' => "{$r['artist']} · {$r['album']}",
                'detail' => implode('; ', $r['reasons']),
                'url' => route('albums.show', $r['album_id']),
            ]),
            'suggestions' => collect(array_slice(Suggestions::all(), 0, $limit))->map(fn ($s) => [
                'title' => $s['title'],
                'detail' => $s['reason'].($s['suggested'] !== null && $s['kind'] === 'tag' ? "\n".ucfirst($s['field']).': '.($s['current'] ?? '(none)').' → '.$s['suggested'] : ''),
                'url' => $s['album_id'] ? route('albums.show', $s['album_id']) : null,
            ]),
            'unlinked', 'missing' => self::query($key)->orderBy('files.path')->limit($limit)->get(['files.path', 'files.size'])->map(fn ($r) => [
                'title' => $r->path,
                'detail' => self::size((int) $r->size),
                'url' => null,
            ]),
            default => self::trackRows($key, $limit),
        };
    }

    /**
     * The findings of one report as data for clients (GET /api/library/health/{report}): ids, a one-line reason in the
     * server's own wording, the file where there is one and, for albums, the physical-collection items that match.
     * Newest-stable order: the report's own order, then the file id; paged by offset.
     *
     * @return list<array{report: string, kind: string, title: string, reason: string, track_id: int|null, album_id: int|null, artist_id: int|null, file: array|null, copies: list<array>|null, physical: list<array>|null}>
     */
    public static function findings(string $key, int $limit = 100, int $offset = 0, bool $withPhysical = true): array
    {
        $findings = match ($key) {
            'duplicates' => self::duplicateFindings($limit, $offset),
            'incomplete' => self::incomplete()->orderByRaw('(have - total) asc')->orderBy('albums.id')->offset($offset)->limit($limit)->get()->map(fn ($r) => self::finding($key, 'album', "{$r->artist} · {$r->album}", "{$r->have} of {$r->total} tracks", albumId: (int) $r->album_id))->all(),
            'covers' => array_map(fn ($r) => self::finding($key, 'album', "{$r['artist']} · {$r['album']}", implode('; ', $r['reasons']), albumId: $r['album_id'], artistId: $r['artist_id']),
                array_slice(self::coverProblems(), $offset, $limit)),
            'suggestions' => array_map(fn ($s) => self::finding($key, $s['kind'], $s['title'], $s['reason'], $s['track_id'], $s['album_id'], $s['artist_id'], suggestion: array_diff_key($s, array_flip(['title', 'reason', 'track_id', 'album_id', 'artist_id']))),
                array_slice(Suggestions::all(), $offset, $limit)),
            'unlinked', 'missing' => self::query($key)->orderBy('files.path')->orderBy('files.id')->offset($offset)->limit($limit)
                ->get(['files.path', 'files.size', 'files.extension'])->map(fn ($r) => self::finding($key, 'file', $r->path, self::size((int) $r->size), file: ['path' => $r->path, 'extension' => $r->extension, 'size' => (int) $r->size]))->all(),
            default => self::trackFindings($key, $limit, $offset),
        };

        $physical = $withPhysical ? self::physicalByAlbum(array_filter(array_column($findings, 'album_id'))) : [];

        return array_map(fn ($f) => ['physical' => $withPhysical && $f['album_id'] ? ($physical[$f['album_id']] ?? []) : null] + $f, $findings);
    }

    private static function finding(string $report, string $kind, string $title, string $reason, ?int $trackId = null, ?int $albumId = null, ?int $artistId = null, ?array $file = null, ?array $copies = null, ?array $suggestion = null): array
    {
        return [
            'report' => $report, 'kind' => $kind, 'title' => $title, 'reason' => $reason,
            'track_id' => $trackId, 'album_id' => $albumId, 'artist_id' => $artistId, 'file' => $file, 'copies' => $copies, 'suggestion' => $suggestion,
        ];
    }

    /** @return list<array> */
    private static function trackFindings(string $key, int $limit, int $offset): array
    {
        $rows = self::query($key)->orderBy('files.id')->offset($offset)->limit($limit)->get([
            'tracks.id as track_id', 'tracks.name as track', 'tracks.album_id', 'tracks.artist_id', 'artists.name as artist', 'files.path', 'files.extension', 'files.sample_rate', 'files.bitrate', 'files.size',
            'file_analyses.spectral_cutoff_hz as cutoff', 'file_analyses.lossless_suspect as suspect', 'file_analyses.clipped_samples as clipped',
            'file_analyses.decode_warnings as warnings',
        ]);

        return $rows->map(fn ($r) => self::finding(
            $key, 'track',
            trim(($r->artist ? $r->artist.' · ' : '').$r->track),
            match ($key) {
                'transcodes' => sprintf('%s, spectrum ends at %s kHz (%d%% sure)', strtoupper((string) $r->extension), number_format($r->cutoff / 1000, 1), round($r->suspect * 100)),
                'damaged' => (string) (json_decode((string) $r->warnings, true)[0] ?? 'decoder warnings'),
                'clipping' => number_format((int) $r->clipped).' peaks at the ceiling',
                default => self::format($r),
            },
            (int) $r->track_id, $r->album_id ? (int) $r->album_id : null, $r->artist_id ? (int) $r->artist_id : null,
            ['path' => $r->path, 'extension' => $r->extension, 'size' => (int) $r->size],
        ))->all();
    }

    /** @return list<array> */
    private static function duplicateFindings(int $limit, int $offset): array
    {
        $groups = self::duplicateGroups()->orderByDesc('copies')->orderBy('recording')->offset($offset)->limit($limit)->get();

        return $groups->map(function ($group) {
            $files = self::files()
                ->join('file_analyses', 'file_analyses.file_id', '=', 'files.id')
                ->leftJoin('track_files', fn ($j) => $j->on('track_files.file_id', '=', 'files.id')->where('track_files.is_primary', true))
                ->leftJoin('tracks', 'tracks.id', '=', 'track_files.track_id')
                ->leftJoin('artists', 'artists.id', '=', 'tracks.artist_id')
                ->where('file_analyses.acoustid_recording_mbid', $group->recording)
                ->get(['files.id as file_id', 'files.path', 'files.extension', 'files.bitrate', 'files.bit_depth', 'files.size', 'file_analyses.spectral_cutoff_hz as cutoff', 'tracks.id as track_id', 'tracks.name as track', 'tracks.album_id', 'tracks.artist_id', 'artists.name as artist']);

            // Best first: lossless, then the higher bit depth and bitrate.
            $files = $files->sortByDesc(fn ($f) => [in_array(strtolower((string) $f->extension), self::LOSSLESS, true), (int) $f->bit_depth, (int) $f->bitrate, (int) $f->cutoff])->values();
            $first = $files->first();

            return self::finding(
                'duplicates', 'group',
                trim(($first->artist ? $first->artist.' · ' : '').($first->track ?? basename($first->path))),
                $files->count().' copies of the same recording; best first',
                $first->track_id ? (int) $first->track_id : null, $first->album_id ? (int) $first->album_id : null, $first->artist_id ? (int) $first->artist_id : null,
                ['path' => $first->path, 'extension' => $first->extension, 'size' => (int) $first->size],
                $files->map(fn ($f) => [
                    'path' => $f->path, 'extension' => $f->extension, 'size' => (int) $f->size, 'track_id' => $f->track_id ? (int) $f->track_id : null,
                    'description' => self::format($f) ?: 'unknown',
                ])->all(),
            );
        })->all();
    }

    /**
     * The physical-collection items (CD, vinyl, tape) tied to these albums, by album id.
     *
     * @param  array<int, int>  $albumIds
     * @return array<int, list<array{id: int, medium: string, ripped: bool, location: string|null}>>
     */
    private static function physicalByAlbum(array $albumIds): array
    {
        if ($albumIds === [] || !Schema::hasTable('physical_items')) {
            return [];
        }

        return DB::table('physical_items')->whereIn('album_id', array_unique($albumIds))->orderBy('id')->get(['id', 'album_id', 'medium', 'ripped_at', 'location'])
            ->groupBy('album_id')
            ->map(fn ($items) => $items->map(fn ($i) => ['id' => (int) $i->id, 'medium' => $i->medium, 'ripped' => $i->ripped_at !== null, 'location' => $i->location])->values()->all())
            ->all();
    }

    /** Present, non-hidden files. */
    private static function files(): Builder
    {
        return DB::table('files')->where('files.status', 'present')->whereNull('files.hidden_at');
    }

    /** A track's primary file, with its analysis when it has one. */
    private static function tracked(): Builder
    {
        return self::files()
            ->join('track_files', fn ($j) => $j->on('track_files.file_id', '=', 'files.id')->where('track_files.is_primary', true))
            ->join('tracks', 'tracks.id', '=', 'track_files.track_id')
            ->leftJoin('artists', 'artists.id', '=', 'tracks.artist_id')
            ->leftJoin('file_analyses', 'file_analyses.file_id', '=', 'files.id');
    }

    private static function query(string $key): Builder
    {
        return match ($key) {
            'transcodes' => self::tracked()->where('file_analyses.lossless_suspect', '>=', 0.5)->orderByDesc('file_analyses.lossless_suspect'),
            'damaged' => self::tracked()->whereNotNull('file_analyses.decode_warnings')->where('file_analyses.decode_warnings', '!=', '[]'),
            'clipping' => self::tracked()->where('file_analyses.clipping_suspect', true)->orderByDesc('file_analyses.clipped_samples'),
            'lossy' => self::tracked()->whereNotIn(DB::raw('lower(files.extension)'), self::LOSSLESS)
                ->orderByRaw('file_analyses.spectral_cutoff_hz is null')->orderBy('file_analyses.spectral_cutoff_hz')->orderBy('files.bitrate'),
            'unlinked' => self::files()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('track_files')->whereColumn('track_files.file_id', 'files.id')),
            'missing' => DB::table('files')->where('files.status', 'missing')->whereNull('files.hidden_at'),
            default => throw new \InvalidArgumentException("Unknown report {$key}"),
        };
    }

    /** @return Collection<int, array{title: string, detail: string, url: ?string}> */
    private static function trackRows(string $key, int $limit): Collection
    {
        $rows = self::query($key)->limit($limit)->get([
            'tracks.name as track', 'tracks.album_id', 'artists.name as artist', 'files.extension', 'files.sample_rate', 'files.bitrate',
            'file_analyses.spectral_cutoff_hz as cutoff', 'file_analyses.lossless_suspect as suspect', 'file_analyses.clipped_samples as clipped',
            'file_analyses.decode_warnings as warnings',
        ]);

        return $rows->map(fn ($r) => [
            'title' => trim(($r->artist ? $r->artist.' · ' : '').$r->track),
            'detail' => match ($key) {
                'transcodes' => sprintf('%s, spectrum ends at %s kHz (%d%% sure)', strtoupper((string) $r->extension), number_format($r->cutoff / 1000, 1), round($r->suspect * 100)),
                'damaged' => (string) (json_decode((string) $r->warnings, true)[0] ?? 'decoder warnings'),
                'clipping' => number_format((int) $r->clipped).' peaks at the ceiling',
                default => self::format($r),
            },
            'url' => $r->album_id ? route('albums.show', $r->album_id) : null,
        ]);
    }

    /** "MP3 · 192 kbps · spectrum ends at 19.0 kHz" */
    private static function format(object $r): string
    {
        // Files report the bitrate as kbps (320) or as bits per second (320000), like Track::bitrateKbps().
        $kbps = $r->bitrate ? ($r->bitrate >= 10000 ? (int) round($r->bitrate / 1000) : (int) $r->bitrate) : null;

        return collect([
            strtoupper((string) $r->extension) ?: null,
            $kbps ? $kbps.' kbps' : null,
            $r->cutoff ? 'spectrum ends at '.number_format($r->cutoff / 1000, 1).' kHz' : null,
        ])->filter()->implode(' · ');
    }

    /** One row per recording (AcoustID) that more than one present file has. */
    private static function duplicateGroups(): Builder
    {
        return self::files()
            ->join('file_analyses', 'file_analyses.file_id', '=', 'files.id')
            ->whereNotNull('file_analyses.acoustid_recording_mbid')
            ->groupBy('file_analyses.acoustid_recording_mbid')
            ->havingRaw('count(distinct files.id) > 1')
            ->select('file_analyses.acoustid_recording_mbid as recording', DB::raw('count(distinct files.id) as copies'));
    }

    /** @return Collection<int, array{title: string, detail: string, url: ?string}> */
    private static function duplicateRows(int $limit): Collection
    {
        $groups = self::duplicateGroups()->orderByDesc('copies')->limit($limit)->get();

        return $groups->map(function ($group) {
            $files = self::files()
                ->join('file_analyses', 'file_analyses.file_id', '=', 'files.id')
                ->leftJoin('track_files', fn ($j) => $j->on('track_files.file_id', '=', 'files.id')->where('track_files.is_primary', true))
                ->leftJoin('tracks', 'tracks.id', '=', 'track_files.track_id')
                ->leftJoin('artists', 'artists.id', '=', 'tracks.artist_id')
                ->where('file_analyses.acoustid_recording_mbid', $group->recording)
                ->get(['files.path', 'files.extension', 'files.bitrate', 'files.bit_depth', 'file_analyses.spectral_cutoff_hz as cutoff', 'tracks.name as track', 'tracks.album_id', 'artists.name as artist']);

            // Best first: lossless, then the higher bit depth and bitrate.
            $files = $files->sortByDesc(fn ($f) => [in_array(strtolower((string) $f->extension), self::LOSSLESS, true), (int) $f->bit_depth, (int) $f->bitrate, (int) $f->cutoff])->values();
            $first = $files->first();

            return [
                'title' => trim(($first->artist ? $first->artist.' · ' : '').($first->track ?? basename($first->path))),
                'detail' => $files->map(fn ($f) => $f->path.' ('.(self::format($f) ?: 'unknown').')')->implode("\n"),
                'url' => $first->album_id ? route('albums.show', $first->album_id) : null,
            ];
        });
    }

    /** Albums with own files that have fewer tracks than the MusicBrainz release lists. */
    private static function incomplete(): Builder
    {
        $have = '(select count(distinct tracks.id) from tracks join track_files on track_files.track_id = tracks.id join files on files.id = track_files.file_id'
            .' where tracks.album_id = albums.id and files.status = \'present\' and files.hidden_at is null)';

        return DB::table('albums')
            ->join('metadata', fn ($j) => $j->on('metadata.metadatable_id', '=', 'albums.id')
                ->where('metadata.metadatable_type', (new Album)->getMorphClass())->where('metadata.key', 'track_count'))
            ->join('artists', 'artists.id', '=', 'albums.artist_id')
            ->whereRaw('cast(metadata.value as integer) > '.$have)
            ->whereRaw($have.' > 0')
            ->select('albums.id as album_id', 'albums.name as album', 'artists.name as artist', DB::raw($have.' as have'), DB::raw('cast(metadata.value as integer) as total'));
    }

    /**
     * Albums with own files whose cover is missing, tiny, not square, or differs from the picture embedded in the
     * files; one entry per album with every reason, worst first (no cover, then the smallest side, then the
     * most crooked, then a differing embedded cover), ties by album id. Thresholds: config/artwork.php. A cover
     * whose size was never recorded (CoverFacts) is not judged for size, only for being absent.
     *
     * @return list<array{album_id: int, artist_id: int|null, album: string, artist: string, reasons: list<string>}>
     */
    public static function coverProblems(): array
    {
        $morph = (new Album)->getMorphClass();
        $meta = fn (string $key) => '(select value from metadata where metadata.metadatable_id = albums.id and metadata.metadatable_type = \''.$morph.'\' and metadata.key = \''.$key.'\' limit 1)';

        $rows = DB::table('albums')
            ->join('artists', 'artists.id', '=', 'albums.artist_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('tracks')
                ->join('track_files', 'track_files.track_id', '=', 'tracks.id')
                ->join('files', 'files.id', '=', 'track_files.file_id')
                ->whereColumn('tracks.album_id', 'albums.id')->where('files.status', 'present')->whereNull('files.hidden_at'))
            ->select('albums.id', 'albums.name', 'albums.artist_id', 'albums.images', 'artists.name as artist')
            ->selectRaw($meta('cover_size').' as cover_size')
            ->selectRaw($meta('cover_sha1').' as cover_sha1')
            ->selectRaw($meta('embedded_cover_sha1').' as embedded_sha1')
            ->orderBy('albums.id')
            ->get();

        $min = (int) config('artwork.min_cover_px');
        $tolerance = (float) config('artwork.square_tolerance');
        $problems = [];

        foreach ($rows as $r) {
            $reasons = [];
            $rank = [9, 0];
            $images = json_decode((string) $r->images, true);

            if (LibraryArtwork::coverUrl(is_array($images) ? $images : null) === null) {
                $reasons[] = 'No cover';
                $rank = [0, 0];
            } elseif ($size = CoverFacts::parse($r->cover_size)) {
                [$w, $h] = $size;
                if (min($w, $h) < $min) {
                    $reasons[] = "Cover is {$w}×{$h} px, under {$min} px";
                    $rank = [1, min($w, $h)];
                }
                if (abs($w - $h) / max($w, $h) > $tolerance) {
                    $reasons[] = "Cover is {$w}×{$h} px, not square";
                    $rank = $rank[0] < 2 ? $rank : [2, -round(abs($w - $h) / max($w, $h) * 1000)];
                }
            }

            if ($r->cover_sha1 && $r->embedded_sha1 && $r->cover_sha1 !== $r->embedded_sha1) {
                $reasons[] = 'The cover embedded in the files differs from the one in use';
                $rank = $rank[0] < 3 ? $rank : [3, 0];
            }

            if ($reasons !== []) {
                $problems[] = ['rank' => $rank, 'album_id' => (int) $r->id, 'artist_id' => $r->artist_id ? (int) $r->artist_id : null, 'album' => $r->name, 'artist' => $r->artist, 'reasons' => $reasons];
            }
        }

        usort($problems, fn ($a, $b) => [$a['rank'][0], $a['rank'][1], $a['album_id']] <=> [$b['rank'][0], $b['rank'][1], $b['album_id']]);

        return array_map(fn ($p) => array_diff_key($p, ['rank' => 1]), $problems);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024).' kB';
    }
}
