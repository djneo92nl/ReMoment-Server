<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\DecodeBranch;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Analysis\Spectrum;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Process;

/**
 * What the audio says about the file's quality, from the shared decode (`DecodePass`), one short excerpt
 * decode and no other tool:
 *
 * - **Whole file** (a filter branch of the shared decode of `astats` and `silencedetect` probes): clipping, mono or out-of-phase
 *   stereo, silence at the start and end, the bit depth the samples really use, and the decoder's warnings
 *   (damaged or truncated files).
 * - **Spectral cutoff** (half a minute from the middle, decoded to mono floats and analysed by `Spectrum`): lossy
 *   encoders cut everything above a frequency (about 16–17 kHz for a 128 kbit/s MP3, 20 kHz for 320). The level of
 *   500 Hz bands from 10 kHz up shows where the spectrum falls off a cliff. `lossless_suspect` (0–1) says how
 *   likely a lossless file is a transcode or an upsampled "hi-res" file. It is a hint, never proof: a recording
 *   that is naturally band-limited looks the same, so clients show "suspect", not "fake".
 */
class QualityStage implements DecodedStage
{
    /** Seconds from the middle of the track the spectrum is measured on (the cutoff belongs to the whole file). */
    private const EXCERPT = 30;

    private const BAND_HZ = 500;

    private const FROM_HZ = 10000;

    /** A band this much quieter than the one below it, with the top band ending that much lower, is the cutoff. */
    private const STEP_DB = 9;

    private const DROP_DB = 25;

    /** Mean power per bin (dB) the 4–8 kHz reference band must have; quieter tracks cannot be judged. */
    private const MIN_REFERENCE_DB = -100;

    private const LOSSLESS_EXTENSIONS = ['flac', 'wav', 'aif', 'aiff', 'ape', 'wv', 'tta', 'alac'];

    public function key(): string
    {
        return 'quality';
    }

    public function version(): string
    {
        return '1';
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
        return new DecodeBranch(self::graph('{in}', '{out}'));
    }

    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array
    {
        $rate = $file->sample_rate ?: 44100;

        $attributes = self::parse($pass->stderr, $rate, $file);

        // Only 30 s, seeked to rather than decoded from the start, so it stays a separate (cheap) run.
        $excerpt = Process::timeout(300)->run([
            Tools::ffmpeg(), '-v', 'error', '-ss', (string) self::excerptStart($file->duration), '-t', (string) self::EXCERPT,
            '-i', $localPath, '-vn', '-af', 'pan=mono|c0=c0', '-ar', (string) $rate, '-f', 'f32le', '-',
        ]);

        if (!$excerpt->successful()) {
            throw new \RuntimeException('ffmpeg could not decode an excerpt: '.self::lastLine($excerpt->errorOutput()));
        }

        return $attributes + self::spectrum($excerpt->output(), $rate, $file);
    }

    /** The filter graph for the whole file: stereo, split into whole-file statistics, mid, side and silence probes. */
    public static function graph(string $in = '[0:a]', string $out = '[out]'): string
    {
        return $in.'aformat=channel_layouts=stereo,asplit=4[s0][s1][s2][s3];'
            .'[s0]astats@all=measure_perchannel=none:measure_overall=Peak_count+Flat_factor+Bit_depth+Number_of_samples'.$out.';'
            .'[s1]pan=mono|c0=0.5*c0+0.5*c1,astats@mid=measure_perchannel=none:measure_overall=RMS_level,anullsink;'
            .'[s2]pan=mono|c0=0.5*c0-0.5*c1,astats@side=measure_perchannel=none:measure_overall=RMS_level,anullsink;'
            .'[s3]silencedetect=noise=-60dB:d=0.3,anullsink';
    }

    /** Seconds into a track where the spectrum excerpt starts: centred, or from the start for a short track. */
    public static function excerptStart(?int $duration): int
    {
        return $duration !== null && $duration > self::EXCERPT ? intdiv($duration - self::EXCERPT, 2) : 0;
    }

    /**
     * What the whole-file pass printed, as `file_analyses` attributes.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $stderr, int $sampleRate, File $file): array
    {
        $stats = self::stats($stderr);
        $level = fn (string $id) => self::decibels($stats[$id]['RMS level dB'] ?? null);
        $all = $stats['all'] ?? [];

        $samples = (int) ($all['Number of samples'] ?? 0);
        $seconds = $samples > 0 ? $samples / $sampleRate : null;

        $peaks = isset($all['Peak count']) ? (int) round((float) $all['Peak count']) : null;
        $flat = isset($all['Flat factor']) ? (float) $all['Flat factor'] : null;
        $mid = $level('mid');
        $side = $level('side');
        $judged = $mid !== null && $side !== null && max($mid, $side) > -80;

        return [
            // A lossy file decodes to 32-bit floats whatever it held, so its measured depth says nothing.
            'measured_bit_depth' => self::isLossless($file) ? self::bitDepth($all['Bit depth'] ?? null) : null,
            'clipped_samples' => $peaks,
            'clipping_suspect' => $peaks !== null && $flat !== null ? ($flat >= 1.0 && $peaks >= 50) : null,
            'is_mono' => $judged ? ($side <= -80 || $mid - $side >= 40) : null,
            'phase_issue' => $judged ? ($side - $mid >= 6) : null,
            ...self::silence($stderr, $seconds),
            'decode_warnings' => self::warnings($stderr, $seconds, $file) ?: null,
        ];
    }

    /**
     * The cutoff and the verdict from the decoded excerpt (32-bit float mono PCM).
     *
     * @return array{spectral_cutoff_hz: ?int, lossless_suspect: ?float}
     */
    public static function spectrum(string $pcm, int $sampleRate, File $file): array
    {
        $bands = Spectrum::bands($pcm, $sampleRate, self::FROM_HZ, self::BAND_HZ);
        $reference = Spectrum::bands($pcm, $sampleRate, 4000, 4000, 12)[4000] ?? null;

        if ($bands === [] || $reference === null || $reference < self::MIN_REFERENCE_DB) {
            return ['spectral_cutoff_hz' => null, 'lossless_suspect' => null];
        }

        $cutoff = self::cutoff($bands);

        return [
            'spectral_cutoff_hz' => $cutoff,
            'lossless_suspect' => self::isLossless($file) ? self::suspect($cutoff, $sampleRate) : null,
        ];
    }

    /**
     * The cutoff in Hz from the levels (dB) of the bands by lower edge: the first band that is 9 dB or more
     * below the one under it while the top band ends up 25 dB or more below that one. A natural roll-off falls
     * a dB or two per band; an encoder's low-pass falls tens of dB within one. Null when there is none.
     *
     * @param  array<int, float>  $levels  lower edge in Hz => level, ascending
     */
    public static function cutoff(array $levels): ?int
    {
        $edges = array_keys($levels);
        if (count($edges) < 3) {
            return null;
        }

        $top = $levels[end($edges)];
        for ($i = 1; $i < count($edges); $i++) {
            $before = $levels[$edges[$i - 1]];
            if (-self::STEP_DB >= $levels[$edges[$i]] - $before && $top <= $before - self::DROP_DB) {
                return $edges[$i];
            }
        }

        return null;
    }

    /**
     * How likely a lossless file at this sample rate is a transcode or upsampled, 0–1, from where its spectrum
     * ends: a cutoff at or under 18 kHz is what 128–160 kbit/s encodes show; 19–19.5 kHz fits 192–256 kbit/s;
     * 20–20.5 kHz is weak evidence (320 kbit/s, or a recording whose own anti-alias filter sits there). Above 48 kHz a cutoff at
     * 22–24 kHz means it began as 44.1 or 48 kHz.
     */
    public static function suspect(?int $cutoff, int $sampleRate): float
    {
        if ($cutoff === null) {
            return 0.0;
        }

        if ($sampleRate > 48000) {
            return match (true) {
                $cutoff <= 22000 => 0.9,
                $cutoff <= 24000 => 0.75,
                default => 0.0,
            };
        }

        return match (true) {
            $cutoff <= 17000 => 0.95,
            $cutoff <= 18000 => 0.9,
            $cutoff <= 19000 => 0.8,
            $cutoff <= 19500 => 0.65,
            $cutoff <= 20000 => 0.45,
            $cutoff <= 20500 => 0.3,
            default => 0.0,
        };
    }

    public static function isLossless(File $file): bool
    {
        return in_array(strtolower((string) $file->extension), self::LOSSLESS_EXTENSIONS, true)
            || preg_match('/flac|alac|pcm|wav|aiff|wavpack|ape|tta/', strtolower((string) $file->codec)) === 1;
    }

    /** @return array<string, array<string, string>> instance id => measure name => value, from the `astats@id` summaries */
    private static function stats(string $stderr): array
    {
        preg_match_all('/\[astats@(\w+) @ [^\]]+\] (RMS level dB|Peak count|Flat factor|Bit depth|Number of samples): (\S+)/', $stderr, $m, PREG_SET_ORDER);

        $stats = [];
        foreach ($m as [, $id, $name, $value]) {
            $stats[$id][$name] = $value;
        }

        return $stats;
    }

    /** dBFS as a float; digital silence (`-inf`) is far below any real level, and a missing value stays null. */
    private static function decibels(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : (str_contains($value, '-inf') ? -200.0 : null);
    }

    /** The largest of astats' "a/b/c/d" bit counts: the bits the samples really use. */
    private static function bitDepth(?string $value): ?int
    {
        if ($value === null || !preg_match_all('/\d+/', $value, $m)) {
            return null;
        }

        return (int) max($m[0]) ?: null;
    }

    /**
     * Leading and trailing silence from `silencedetect` (below -60 dB for 0.3 s or more).
     *
     * @return array{lead_silence_ms: int, trail_silence_ms: int}
     */
    private static function silence(string $stderr, ?float $seconds): array
    {
        preg_match_all('/silence_start: (-?[\d.e+-]+)/', $stderr, $starts);
        preg_match_all('/silence_end: (-?[\d.e+-]+)/', $stderr, $ends);

        $starts = array_map('floatval', $starts[1]);
        $ends = array_map('floatval', $ends[1]);

        $lead = ($starts && $starts[0] <= 0.05 && $ends) ? (int) round($ends[0] * 1000) : 0;
        $trail = ($seconds !== null && count($starts) > count($ends)) ? (int) round(max(0, $seconds - end($starts)) * 1000) : 0;

        return ['lead_silence_ms' => $lead, 'trail_silence_ms' => $trail];
    }

    /**
     * What the decoder complained about, and a track that decoded much shorter than its tags say.
     *
     * @return list<string>
     */
    private static function warnings(string $stderr, ?float $seconds, File $file): array
    {
        $warnings = [];

        foreach (preg_split('/\R/', $stderr) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_contains($line, 'astats@') && !str_contains($line, 'silencedetect') && !str_starts_with($line, 'video:')
                && preg_match('/error|invalid|corrupt|truncat|missing|overread|\bcrc\b/i', $line)) {
                $warnings[] = mb_substr(preg_replace('/^\[[^\]]*\]\s*/', '', $line), 0, 160);
            }
        }

        if ($seconds !== null && $file->duration && $seconds < $file->duration - 2) {
            $warnings[] = sprintf('decoded %.0f s, the tags say %d s', $seconds, $file->duration);
        }

        return array_slice(array_values(array_unique($warnings)), 0, 5);
    }

    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return end($lines) ?: 'no output';
    }
}
