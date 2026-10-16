<?php

namespace App\Domain\Artwork;

/** Hex ↔ HSL for the colours drawn from artwork (AccentColor, ArtworkTint). */
final class Hsl
{
    /** @return array{0: float, 1: float, 2: float}|null hue 0-360, saturation and lightness 0-1; null for a non-hex string */
    public static function fromHex(string $hex): ?array
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

    public static function toHex(float $h, float $s, float $l): string
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
