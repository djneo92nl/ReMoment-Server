<?php

namespace App\Domain\Analysis;

/**
 * The average power spectrum of a piece of mono audio, in bands: Hann-windowed FFTs of evenly spaced frames,
 * averaged (Welch), so one loud moment does not decide. Plain PHP and a few hundred thousand operations per
 * frame, which keeps the analysis free of extra binaries on a Raspberry Pi.
 */
final class Spectrum
{
    public const FRAME = 4096;

    /** Frames whose RMS is below this (dBFS) are silence and say nothing about the encoder. */
    private const SILENCE_DB = -70;

    /** @var array<int, array{cos: list<float>, sin: list<float>, rev: list<int>}> */
    private static array $plans = [];

    /** @var list<float>|null */
    private static ?array $window = null;

    /**
     * The mean power (dBFS per bin, 0 dB = a full-scale sine's bin) of each band of $bandHz from $fromHz up to the
     * Nyquist frequency, by lower edge in Hz. Empty when the audio holds no frame above the silence floor.
     *
     * @param  string  $pcm  32-bit float little-endian mono samples
     * @return array<int, float>
     */
    public static function bands(string $pcm, int $sampleRate, int $fromHz, int $bandHz, int $frames = 48): array
    {
        $n = self::FRAME;
        $total = intdiv(strlen($pcm), 4);
        if ($total < $n) {
            return [];
        }

        $window = self::window();
        $energy = array_sum(array_map(fn ($w) => $w * $w, $window));
        $power = array_fill(0, $n / 2, 0.0);
        $used = 0;

        $starts = $frames >= 2 && $total > $n * 2
            ? array_map(fn ($i) => (int) round($i * ($total - $n) / ($frames - 1)), range(0, $frames - 1))
            : [0];

        foreach (array_unique($starts) as $start) {
            $samples = array_values(unpack('g'.$n, substr($pcm, $start * 4, $n * 4)));

            $rms = sqrt(array_sum(array_map(fn ($s) => $s * $s, $samples)) / $n);
            if ($rms <= 0 || 20 * log10($rms) < self::SILENCE_DB) {
                continue;
            }

            [$re, $im] = self::fft(array_map(fn ($s, $w) => $s * $w, $samples, $window));
            for ($k = 0; $k < $n / 2; $k++) {
                $power[$k] += ($re[$k] * $re[$k] + $im[$k] * $im[$k]);
            }
            $used++;
        }

        if ($used === 0) {
            return [];
        }

        // One-sided power per bin, 0 dB for a full-scale sine: 4 * |X|^2 / (N * sum(w^2) ...) reduces to this scale.
        $scale = 4.0 / ($energy * $n);
        $binHz = $sampleRate / $n;
        $bands = [];

        for ($low = $fromHz; $low < $sampleRate / 2 - $bandHz / 2; $low += $bandHz) {
            $from = (int) ceil($low / $binHz);
            $to = min((int) floor(($low + $bandHz) / $binHz), $n / 2 - 1);
            if ($to < $from) {
                continue;
            }

            $mean = array_sum(array_slice($power, $from, $to - $from + 1)) / ($to - $from + 1) / $used * $scale;
            $bands[$low] = $mean > 0 ? 10 * log10($mean) : -200.0;
        }

        return $bands;
    }

    /**
     * @param  list<float>  $x  FRAME real samples
     * @return array{0: list<float>, 1: list<float>} real and imaginary parts
     */
    public static function fft(array $x): array
    {
        $n = count($x);
        $plan = self::plan($n);

        $re = [];
        foreach ($plan['rev'] as $i => $j) {
            $re[$i] = $x[$j];
        }
        $im = array_fill(0, $n, 0.0);

        for ($size = 2; $size <= $n; $size <<= 1) {
            $half = $size >> 1;
            $step = intdiv($n, $size);

            for ($start = 0; $start < $n; $start += $size) {
                for ($k = 0, $t = 0; $k < $half; $k++, $t += $step) {
                    $a = $start + $k;
                    $b = $a + $half;
                    $c = $plan['cos'][$t];
                    $s = $plan['sin'][$t];
                    $tr = $re[$b] * $c - $im[$b] * $s;
                    $ti = $re[$b] * $s + $im[$b] * $c;
                    $re[$b] = $re[$a] - $tr;
                    $im[$b] = $im[$a] - $ti;
                    $re[$a] += $tr;
                    $im[$a] += $ti;
                }
            }
        }

        return [$re, $im];
    }

    /** @return array{cos: list<float>, sin: list<float>, rev: list<int>} */
    private static function plan(int $n): array
    {
        if (isset(self::$plans[$n])) {
            return self::$plans[$n];
        }

        $bits = (int) log($n, 2);
        $rev = [];
        for ($i = 0; $i < $n; $i++) {
            $r = 0;
            for ($b = 0; $b < $bits; $b++) {
                $r |= (($i >> $b) & 1) << ($bits - 1 - $b);
            }
            $rev[$i] = $r;
        }

        $cos = $sin = [];
        for ($i = 0; $i < $n / 2; $i++) {
            $cos[$i] = cos(-2 * M_PI * $i / $n);
            $sin[$i] = sin(-2 * M_PI * $i / $n);
        }

        return self::$plans[$n] = ['cos' => $cos, 'sin' => $sin, 'rev' => $rev];
    }

    /** @return list<float> */
    private static function window(): array
    {
        if (self::$window === null) {
            $n = self::FRAME;
            self::$window = array_map(fn ($i) => 0.5 - 0.5 * cos(2 * M_PI * $i / ($n - 1)), range(0, $n - 1));
        }

        return self::$window;
    }
}
