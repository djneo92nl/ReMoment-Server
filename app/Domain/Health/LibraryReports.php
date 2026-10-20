<?php

namespace App\Domain\Health;

use App\Models\Media\Album;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            'unlinked', 'missing' => self::query($key)->orderBy('files.path')->limit($limit)->get(['files.path', 'files.size'])->map(fn ($r) => [
                'title' => $r->path,
                'detail' => self::size((int) $r->size),
                'url' => null,
            ]),
            default => self::trackRows($key, $limit),
        };
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

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024).' kB';
    }
}
