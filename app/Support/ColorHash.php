<?php

namespace App\Support;

class ColorHash
{
    /**
     * Deterministic two-color gradient derived from a seed string, used as a
     * last-resort fallback when no real artwork colors are available yet.
     *
     * @return array{0: string, 1: string}
     */
    public static function gradient(string $seed): array
    {
        $hue1 = crc32($seed) % 360;
        $hue2 = ($hue1 + 40) % 360;

        return ["hsl({$hue1}, 55%, 45%)", "hsl({$hue2}, 55%, 45%)"];
    }
}
