<?php

namespace App\Domain\Artwork;

use GdImage;
use InvalidArgumentException;

/**
 * Draws a SourceLogo glyph: an off-white line icon on a dark square, in the
 * spirit of the black, minimal BeoSound Moment UI. Glyphs are designed on a
 * 100×100 grid, drawn at 4× and downsampled for smooth edges. Output is
 * deterministic for a given key and size.
 */
final class LogoRenderer
{
    private const SUPERSAMPLE = 4;

    private const BACKGROUND = [24, 24, 24];

    private const FOREGROUND = [230, 230, 230];

    private GdImage $gd;

    private float $scale;

    private int $fg;

    private int $bg;

    /** PNG bytes of the logo, $size pixels square. */
    public static function render(string $key, int $size = 512): string
    {
        if (!in_array($key, SourceLogo::KEYS, true)) {
            throw new InvalidArgumentException("Unknown logo [{$key}].");
        }

        return (new self($size * self::SUPERSAMPLE))->draw($key)->toPng($size);
    }

    private function __construct(int $canvas)
    {
        $this->gd = imagecreatetruecolor($canvas, $canvas);
        $this->scale = $canvas / 100;
        $this->bg = imagecolorallocate($this->gd, ...self::BACKGROUND);
        $this->fg = imagecolorallocate($this->gd, ...self::FOREGROUND);
        imagefill($this->gd, 0, 0, $this->bg);
    }

    private function draw(string $key): self
    {
        match ($key) {
            'spotify' => $this->spotify(),
            'bluetooth' => $this->bluetooth(),
            'cast' => $this->cast(),
            'radio' => $this->radio(),
            'line_in' => $this->lineIn(),
            'cd' => $this->cd(),
            'tv' => $this->tv(),
            default => $this->music(),
        };

        return $this;
    }

    private function toPng(int $size): string
    {
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $this->gd, 0, 0, 0, 0, $size, $size, imagesx($this->gd), imagesy($this->gd));

        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    // Glyphs (100×100 grid, the glyph kept within roughly 26–74)

    private function music(): void
    {
        // Two beamed eighth notes.
        $this->disc(37, 67, 7);
        $this->disc(61, 62, 7);
        $this->line(42.5, 66, 42.5, 33, 3.6);
        $this->line(66.5, 61, 66.5, 28, 3.6);
        $this->polygon([[40.7, 30], [68.3, 25], [68.3, 32], [40.7, 37]]);
    }

    private function radio(): void
    {
        // Broadcast: a dot with two arcs either side.
        $this->disc(50, 50, 5);
        foreach ([[13, -40, 40], [13, 140, 220], [23, -40, 40], [23, 140, 220]] as [$r, $from, $to]) {
            $this->arc(50, 50, $r, $from, $to, 4);
        }
    }

    private function spotify(): void
    {
        $this->disc(50, 50, 24);
        $this->arc(50, 76, 30, 236, 304, 4.6, $this->bg);
        $this->arc(50, 82, 27, 241, 299, 3.8, $this->bg);
        $this->arc(50, 86, 23, 244, 296, 3.2, $this->bg);
    }

    private function bluetooth(): void
    {
        $this->polyline([[39, 38], [61, 60], [50, 71], [50, 29], [61, 40], [39, 62]], 4);
    }

    private function tv(): void
    {
        $this->polyline([[27, 31], [73, 31], [73, 62], [27, 62], [27, 31]], 4);
        $this->line(40, 71, 60, 71, 4);
    }

    private function cast(): void
    {
        // AirPlay-style: a screen open at the bottom with a triangle in the gap.
        $this->polyline([[38, 62], [27, 62], [27, 29], [73, 29], [73, 62], [62, 62]], 4);
        $this->polygon([[50, 55], [62, 73], [38, 73]]);
    }

    private function lineIn(): void
    {
        // A jack plug: tip, banded shaft, collar, grip and cable.
        $this->disc(50, 28, 3.2);
        $this->rect(46.8, 28, 53.2, 47);
        $this->rect(46.8, 34, 53.2, 35.6, $this->bg);
        $this->rect(46.8, 40, 53.2, 41.6, $this->bg);
        $this->rect(43, 47, 57, 50.5);
        $this->rect(44, 50.5, 56, 66);
        $this->line(50, 66, 50, 76, 3.2);
    }

    private function cd(): void
    {
        $this->disc(50, 50, 24);
        $this->disc(50, 50, 8, $this->bg);
        $this->disc(50, 50, 3.5);
        $this->arc(50, 50, 16, 200, 250, 2.6, $this->bg);
    }

    // Drawing primitives, in grid units

    private function disc(float $x, float $y, float $r, ?int $color = null): void
    {
        $d = (int) round(2 * $r * $this->scale);
        imagefilledellipse($this->gd, $this->px($x), $this->px($y), $d, $d, $color ?? $this->fg);
    }

    private function rect(float $x1, float $y1, float $x2, float $y2, ?int $color = null): void
    {
        imagefilledrectangle($this->gd, $this->px($x1), $this->px($y1), $this->px($x2), $this->px($y2), $color ?? $this->fg);
    }

    /** @param array<int, array{0: float, 1: float}> $points */
    private function polygon(array $points, ?int $color = null): void
    {
        $flat = [];
        foreach ($points as [$x, $y]) {
            $flat[] = $this->px($x);
            $flat[] = $this->px($y);
        }
        imagefilledpolygon($this->gd, $flat, $color ?? $this->fg);
    }

    /** A stroke with round caps, stamped as overlapping discs. */
    private function line(float $x1, float $y1, float $x2, float $y2, float $width, ?int $color = null): void
    {
        $steps = max(1, (int) ceil(hypot($x2 - $x1, $y2 - $y1) / ($width / 8)));
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $this->disc($x1 + ($x2 - $x1) * $t, $y1 + ($y2 - $y1) * $t, $width / 2, $color);
        }
    }

    /** @param array<int, array{0: float, 1: float}> $points */
    private function polyline(array $points, float $width): void
    {
        for ($i = 1; $i < count($points); $i++) {
            $this->line($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $width);
        }
    }

    /** An arc stroke; angles in degrees, clockwise from 3 o'clock (GD's convention). */
    private function arc(float $cx, float $cy, float $r, float $from, float $to, float $width, ?int $color = null): void
    {
        $steps = max(1, (int) ceil(deg2rad($to - $from) * $r / ($width / 8)));
        for ($i = 0; $i <= $steps; $i++) {
            $a = deg2rad($from + ($to - $from) * $i / $steps);
            $this->disc($cx + $r * cos($a), $cy + $r * sin($a), $width / 2, $color);
        }
    }

    private function px(float $units): int
    {
        return (int) round($units * $this->scale);
    }
}
