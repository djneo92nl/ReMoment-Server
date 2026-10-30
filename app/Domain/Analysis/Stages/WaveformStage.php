<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\DecodeBranch;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;

/**
 * The waveform of a file, from the audio decoded by ffmpeg to 4 kHz mono: `analysis.waveform_points` buckets.
 *
 * `waveform` is the RMS of each bucket in dB, optionally minus a share of the local loudness (strong on a
 * brickwalled track, none on a dynamic one: the share follows the PLR), stretched per track between a
 * floor and a ceiling percentile to integers 0–100, so a flat, loud track still shows its structure.
 * `waveform_peak` is the old picture: each bucket's loudest sample, 0–100 relative to the file's loudest.
 * See docs/architecture/audio-analysis.md.
 */
class WaveformStage implements DecodedStage
{
    /** Samples per second the file is decoded to; peaks need no more. */
    private const RATE = 4000;

    public function key(): string
    {
        return 'waveform';
    }

    public function version(): string
    {
        return '2';
    }

    public function enabled(): bool
    {
        return Tools::available(Tools::ffmpeg());
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        return DecodePass::runAlone($this, $file, $localPath, $analysis);
    }

    public function branch(File $file): DecodeBranch
    {
        return new DecodeBranch('{in}aformat=channel_layouts=mono,aresample='.self::RATE.',aformat=sample_fmts=s16{out}', ['-f', 's16le']);
    }

    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array
    {
        $plr = $analysis->true_peak !== null && $analysis->loudness_lufs !== null
            ? $analysis->true_peak - $analysis->loudness_lufs
            : null;

        $wave = self::analyse((string) file_get_contents($pass->file($this->key())), (int) config('analysis.waveform_points'), $plr);

        return ['waveform' => $wave['waveform'], 'waveform_peak' => $wave['peak']];
    }

    /**
     * Peaks of signed 16-bit little-endian mono PCM: the loudest sample of each of $points equal parts,
     * scaled so the loudest of all is 100. Fewer parts when there are fewer samples.
     *
     * @return list<int>
     */
    public static function peaks(string $pcm, int $points): array
    {
        return self::buckets($pcm, $points)['peak'];
    }

    /**
     * Both waveforms of the PCM: `waveform` (contrast-mapped RMS) and `peak` (see peaks()).
     *
     * @param  ?float  $plr  peak-to-loudness ratio in dB (true peak minus integrated LUFS); null skips the deviation layer
     * @return array{waveform: list<int>, peak: list<int>}
     */
    public static function analyse(string $pcm, int $points, ?float $plr = null): array
    {
        $b = self::buckets($pcm, $points);
        $count = count($b['peak']);
        $seconds = intdiv(strlen($pcm), 2) / self::RATE;

        return [
            'waveform' => self::contrast($b['rms'], $plr === null ? 0.0 : self::strength($plr), $count > 0 ? $seconds / $count : 1.0),
            'peak' => $b['peak'],
        ];
    }

    /** Share of the local loudness subtracted: `deviation_max` at or below `deviation_plr_low`, 0 at or above `deviation_plr_high`, linear between. */
    public static function strength(float $plr): float
    {
        $low = (float) config('analysis.deviation_plr_low', 8);
        $high = (float) config('analysis.deviation_plr_high', 14);
        $t = $high > $low ? ($high - $plr) / ($high - $low) : ($plr <= $low ? 1.0 : 0.0);

        return (float) config('analysis.deviation_max', 0.7) * max(0.0, min(1.0, $t));
    }

    /**
     * Maps bucket RMS values (0–32768) to integers 0–100: dB, minus $strength × the moving average (window of
     * `deviation_window_seconds`), then the 5th percentile → 0 and the 99th → 100. The floor is never closer
     * than `contrast_min_span` dB to the ceiling (a constant tone stays flat instead of turning into noise) nor
     * further than `contrast_max_span`. Silence (ceiling below -90 dB) is all 0.
     *
     * @param  list<float>  $rms
     * @return list<int>
     */
    public static function contrast(array $rms, float $strength, float $secondsPerBucket): array
    {
        $n = count($rms);
        $silent = -90.0;
        $db = array_map(fn (float $v) => $v > 0 ? max(-96.0, 20 * log10($v / 32768)) : -96.0, $rms);

        if ($n === 0 || max($db) <= $silent) {
            return array_fill(0, $n, 0);
        }

        $curve = $db;

        if ($strength > 0 && $n > 2) {
            $half = max(1, (int) round((float) config('analysis.deviation_window_seconds', 4) / max($secondsPerBucket, 0.001) / 2));
            $prefix = [0.0];
            foreach ($db as $v) {
                $prefix[] = end($prefix) + $v;
            }
            for ($i = 0; $i < $n; $i++) {
                $from = max(0, $i - $half);
                $to = min($n - 1, $i + $half);
                $curve[$i] = $db[$i] - $strength * (($prefix[$to + 1] - $prefix[$from]) / ($to - $from + 1));
            }
        }

        $sorted = $curve;
        sort($sorted);
        $ceiling = self::percentile($sorted, 99);
        $floor = self::percentile($sorted, 5);
        $floor = min($floor, $ceiling - (float) config('analysis.contrast_min_span', 6));
        $floor = max($floor, $ceiling - (float) config('analysis.contrast_max_span', 30));
        $span = max(1.0, $ceiling - $floor);

        return array_map(fn (float $v) => (int) round(max(0.0, min(1.0, ($v - $floor) / $span)) * 100), $curve);
    }

    /** Linear-interpolated percentile (0–100) of an ascending list. */
    public static function percentile(array $sorted, float $p): float
    {
        $pos = (count($sorted) - 1) * $p / 100;
        $lo = (int) floor($pos);
        $hi = min(count($sorted) - 1, $lo + 1);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
    }

    /**
     * RMS (raw, 0–32768) and peak (0–100 relative to the loudest sample) per bucket; fewer buckets when
     * there are fewer samples. One bucket's samples are unpacked at a time.
     *
     * @return array{rms: list<float>, peak: list<int>}
     */
    private static function buckets(string $pcm, int $points): array
    {
        $samples = intdiv(strlen($pcm), 2);

        if ($samples === 0) {
            throw new \RuntimeException('the file has no audio to draw');
        }

        $points = max(1, min($points, $samples));
        $peaks = [];
        $rms = [];

        for ($i = 0; $i < $points; $i++) {
            $from = intdiv($i * $samples, $points);
            $to = intdiv(($i + 1) * $samples, $points);
            $values = unpack('s*', substr($pcm, $from * 2, max(1, $to - $from) * 2));
            $sum = 0;
            $max = 0;
            foreach ($values as $v) {
                $sum += $v * $v;
                $a = $v < 0 ? -$v : $v;
                if ($a > $max) {
                    $max = $a;
                }
            }
            $peaks[] = $max;
            $rms[] = sqrt($sum / count($values));
        }

        $loudest = max($peaks) ?: 1;

        return ['rms' => $rms, 'peak' => array_map(fn (int $peak) => (int) round($peak / $loudest * 100), $peaks)];
    }
}
