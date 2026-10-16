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
        $base = isset($colors[0]) ? Hsl::fromHex($colors[0]) : null;
        if ($base === null) {
            return self::FALLBACK;
        }

        $best = null;
        foreach ($safeColors as $hex) {
            $hsl = Hsl::fromHex($hex);
            if ($hsl === null || $hsl[1] < 0.35 || self::hueDistance($hsl[0], $base[0]) < 40) {
                continue;
            }
            if ($best === null || $hsl[1] > $best[1]) {
                $best = $hsl;
            }
        }
        if ($best !== null) {
            return Hsl::toHex($best[0], $best[1], max($best[2], 0.6));
        }

        // A grey cover has no hue to oppose: a plain light accent reads best.
        if ($base[1] < 0.15) {
            return self::FALLBACK;
        }

        return Hsl::toHex(fmod($base[0] + 180, 360), max($base[1], 0.6), 0.62);
    }

    private static function hueDistance(float $a, float $b): float
    {
        $d = abs($a - $b);

        return $d > 180 ? 360 - $d : $d;
    }
}
