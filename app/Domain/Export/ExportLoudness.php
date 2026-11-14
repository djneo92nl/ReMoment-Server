<?php

namespace App\Domain\Export;

use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Track;
use InvalidArgumentException;

/**
 * The gain (dB) an export bakes into a transcoded file for the `loudness` option (`off`, `album`, `track`), from what
 * the analysis stage stored: the file's ReplayGain tags, else its measured loudness (`loudness_lufs`) against the
 * ReplayGain 2 reference (-18 LUFS). Never clips: a boost is limited to the headroom left by the stored true peak
 * (less `export.loudness_margin_db`) and to `export.loudness_max_gain_db`; a file without a measured peak is never boosted.
 *
 * `album` keeps the levels of an album's tracks relative to each other: the album gain (tag, else the duration-weighted
 * energy mean of the album's tracks in this export) is limited by the loudest peak of those tracks. Without an album
 * gain a track falls back to its track gain, without any value it gets none. Copies never get a gain (bit-exact).
 */
final class ExportLoudness
{
    public const MODES = ['off', 'album', 'track'];

    public static function normalise(mixed $mode): string
    {
        $mode = is_string($mode) ? strtolower($mode) : 'off';
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown loudness mode [{$mode}].");
        }

        return $mode;
    }

    /**
     * @param  array<int, array{track: Track, file: File}>  $entries  track id => track and its file (the ones that may be transcoded)
     * @param  array{reference?: float, margin?: float, max?: float}  $limits  override the export config (playback uses `proxies.loudness`)
     * @return array<int, ?float> track id => gain in dB (0.0 = leave as it is), null = nothing known to level with
     */
    public function gains(array $entries, string $mode, array $limits = []): array
    {
        $mode = self::normalise($mode);
        if ($mode === 'off' || $entries === []) {
            return [];
        }

        $analyses = FileAnalysis::query()->whereIn('file_id', array_map(fn ($e) => $e['file']->id, $entries))->get()->keyBy('file_id');
        $margin = (float) ($limits['margin'] ?? config('export.loudness_margin_db'));
        $reference = (float) ($limits['reference'] ?? config('export.loudness_reference_lufs'));
        $max = (float) ($limits['max'] ?? config('export.loudness_max_gain_db'));

        $albums = [];
        foreach ($entries as $trackId => $e) {
            $albums[(int) $e['track']->album_id ?: -$trackId][] = $trackId;
        }

        $gains = [];
        foreach ($albums as $trackIds) {
            $albumGain = $mode === 'album' ? $this->albumGain($trackIds, $entries, $analyses, $reference) : null;
            $loudestPeak = $this->peak(array_map(fn ($id) => $analyses[$entries[$id]['file']->id] ?? null, $trackIds));

            foreach ($trackIds as $id) {
                $a = $analyses[$entries[$id]['file']->id] ?? null;
                $wanted = $albumGain ?? ($a ? $this->trackGain($a, $reference) : null);
                $peak = $mode === 'album' && $albumGain !== null ? $loudestPeak : ($a?->true_peak);
                $gains[$id] = $wanted === null ? null : $this->limit($wanted, $peak, $margin, $max);
            }
        }

        return $gains;
    }

    private function trackGain(FileAnalysis $a, float $reference): ?float
    {
        if ($a->replaygain_track_gain !== null) {
            return $a->replaygain_track_gain;
        }

        return $a->loudness_lufs !== null ? $reference - $a->loudness_lufs : null;
    }

    /** @param  list<int>  $trackIds */
    private function albumGain(array $trackIds, array $entries, $analyses, float $reference): ?float
    {
        foreach ($trackIds as $id) {
            $tag = ($analyses[$entries[$id]['file']->id] ?? null)?->replaygain_album_gain;
            if ($tag !== null) {
                return $tag;
            }
        }

        // No album tag: the energy mean of the tracks' integrated loudness, weighted by duration; every track must be measured.
        $energy = 0.0;
        $seconds = 0.0;
        foreach ($trackIds as $id) {
            $lufs = ($analyses[$entries[$id]['file']->id] ?? null)?->loudness_lufs;
            if ($lufs === null) {
                return null;
            }
            $d = max(1.0, (float) ($entries[$id]['file']->duration ?: $entries[$id]['track']->duration ?: 1));
            $energy += $d * 10 ** ($lufs / 10);
            $seconds += $d;
        }

        return $seconds > 0 ? $reference - 10 * log10($energy / $seconds) : null;
    }

    /** The highest true peak (dBFS) of the analyses; null when one is unknown, because then nothing can be promised. */
    private function peak(array $analyses): ?float
    {
        $peak = null;
        foreach ($analyses as $a) {
            if (!$a || $a->true_peak === null) {
                return null;
            }
            $peak = $peak === null ? $a->true_peak : max($peak, $a->true_peak);
        }

        return $peak;
    }

    private function limit(float $gain, ?float $peak, float $margin, float $max): float
    {
        $gain = min($gain, $max);
        if ($gain > 0) {
            $gain = $peak === null ? 0.0 : min($gain, max(0.0, -$peak - $margin));
        }
        $gain = round($gain, 1);

        return abs($gain) < 0.1 ? 0.0 : $gain;
    }
}
