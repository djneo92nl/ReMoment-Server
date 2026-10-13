<?php

namespace App\Domain\Artwork;

/** The accent of a player drawn on the artwork's colors: stands out from the background, whatever the cover. */
final class AccentColor
{
    public const FALLBACK = '#ffffff';

    /**
     * The most vivid of the light `safe_colors` whose hue is not the background's own; else the background's
     * complementary hue, lifted to a light, saturated tone.
     *
     * @param  string[]  $colors  the artwork's dominant colors (the first is the background's start)
     * @param  string[]  $safeColors  the same lightened for dark backgrounds
     */
    public static function for(array $colors, array $safeColors = []): string
    {
        $base = isset($colors[0]) ? self::toHsl($colors[0]) : null;
        if ($base === null) {
            return self::FALLBACK;
        }

        $best = null;
        foreach ($safeColors as $hex) {
            $hsl = self::toHsl($hex);
            if ($hsl === null || $hsl[1] < 0.35 || self::hueDistance($hsl[0], $base[0]) < 40) {
                continue;
            }
            if ($best === null || $hsl[1] > $best[1]) {
                $best = $hsl;
            }
        }
        if ($best !== null) {
            return self::toHex($best[0], $best[1], max($best[2], 0.6));
        }

        // A grey cover has no hue to oppose: a plain light accent reads best.
        if ($base[1] < 0.15) {
            return self::FALLBACK;
        }

        return self::toHex(fmod($base[0] + 180, 360), max($base[1], 0.6), 0.62);
    }

    private static function hueDistance(float $a, float $b): float
    {
        $d = abs($a - $b);

        return $d > 180 ? 360 - $d : $d;
    }

    /** @return array{0: float, 1: float, 2: float}|null hue 0-360, saturation and lightness 0-1 */
    private static function toHsl(string $hex): ?array
    {
        if (!preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            return null;
        }
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split($m[1], 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function toHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
    }
}
