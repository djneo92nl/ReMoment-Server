<?php

namespace App\Domain\Analysis;

use App\Domain\Analysis\Stages\AcoustIdStage;
use App\Domain\Analysis\Stages\EssentiaStage;
use App\Domain\Analysis\Stages\FingerprintStage;
use App\Domain\Analysis\Stages\LoudnessStage;
use App\Domain\Analysis\Stages\QualityStage;
use App\Domain\Analysis\Stages\WaveformStage;
use App\Domain\Library\Enrichment;
use App\Jobs\AnalyzeAudioFile;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\TrackFile;
use Illuminate\Support\Collection;

/**
 * Audio analysis of the server's own files: which stages exist, which files still need work, and what a
 * finished analysis does for the library. Only files with a track can be analysed (the app never sees the
 * audio of DLNA or Spotify tracks).
 */
class AudioAnalysis
{
    public static function enabled(): bool
    {
        return (bool) config('analysis.enabled');
    }

    /**
     * Every stage in run order; the fingerprint comes before the lookup that needs it.
     *
     * @return array<string, AnalysisStage>
     */
    public static function stages(): array
    {
        $stages = [new LoudnessStage, new WaveformStage, new QualityStage, new FingerprintStage, new AcoustIdStage, new EssentiaStage];

        return array_combine(array_map(fn (AnalysisStage $s) => $s->key(), $stages), $stages);
    }

    /** @return array<string, AnalysisStage> the stages this install can run, optionally only one */
    public static function enabledStages(?string $only = null): array
    {
        return array_filter(
            self::stages(),
            fn (AnalysisStage $stage) => ($only === null || $stage->key() === $only) && $stage->enabled(),
        );
    }

    /**
     * The stages a file still needs: all of them when it was never analysed or changed since, else those
     * whose version moved on or that failed fewer times than `analysis.max_attempts`.
     *
     * @param  array<string, AnalysisStage>  $stages
     * @return array<string, AnalysisStage>
     */
    public static function pendingStages(File $file, ?FileAnalysis $analysis, array $stages): array
    {
        if ($analysis === null || $analysis->content_hash !== $file->hash) {
            return $stages;
        }

        return array_filter($stages, fn (AnalysisStage $stage) => $analysis->stageVersion($stage->key()) !== $stage->version()
            && !$analysis->gaveUp($stage->key(), $stage->version()));
    }

    /** Files with something left to analyse, newest first, up to $limit (null = all, to count them). */
    public static function pending(?int $limit = null, ?string $only = null): Collection
    {
        $stages = self::enabledStages($only);
        $found = collect();

        if (!$stages) {
            return $found;
        }

        File::query()
            ->where('status', File::PRESENT)
            ->whereIn('id', TrackFile::query()->where('role', TrackFile::ORIGINAL)->where('is_primary', true)->select('file_id'))
            ->with('analysis')
            ->orderByDesc('id')
            ->lazyById(500)
            ->each(function (File $file) use ($stages, $limit, $found) {
                if (self::pendingStages($file, $file->analysis, $stages)) {
                    $found->push($file);
                }

                return $limit === null || $found->count() < $limit;
            });

        return $found;
    }

    /** Queues up to $limit files that still need analysis; returns how many. */
    public static function queueBacklog(int $limit, ?string $only = null): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $files = self::pending($limit, $only);
        $files->each(fn (File $file) => AnalyzeAudioFile::dispatch($file, $only));

        return $files->count();
    }

    /**
     * What an analysis does for the library: the recording id AcoustID found becomes the tracks' `mbid`
     * (source `acoustid`, which the MusicBrainz job then uses instead of a text search), and classifier
     * genres stand in as genres while a track has none.
     */
    public static function applyEffects(File $file, FileAnalysis $analysis): void
    {
        foreach ($file->tracks()->wherePivot('is_primary', true)->get() as $track) {
            if ($analysis->acoustid_recording_mbid) {
                Enrichment::save($track, 'mbid', $analysis->acoustid_recording_mbid, 'string', Enrichment::ACOUSTID);
            }

            if ($analysis->genre_tags) {
                Enrichment::fallbackGenres($track, array_column($analysis->genre_tags, 'name'), 'analysis');
            }
        }
    }
}
