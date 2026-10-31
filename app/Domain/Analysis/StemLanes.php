<?php

namespace App\Domain\Analysis;

/**
 * Turns a stem's loudness curve (integer dB per frame, `file_analyses.stems`) into what a lane draws: one height 0-1
 * per column, on the lane's own scale. The stems have very different levels (bass sits far under the drums), so each
 * lane runs from its own loudest 1 % down RANGE_DB; a silent lane stays flat. The view only draws the result.
 */
final class StemLanes
{
    /** dB under a lane's own loudest 1 % that still shows as a bar. */
    public const RANGE_DB = 40;

    /** At most this many columns per lane; a longer curve takes the loudest frame of each column. */
    public const MAX_COLUMNS = 400;

    /**
     * @param  list<int|float>  $curve
     * @return list<float> heights 0-1, `min(count($curve), $maxColumns)` of them
     */
    public static function heights(array $curve, int $maxColumns = self::MAX_COLUMNS): array
    {
        $n = count($curve);
        if ($n === 0) {
            return [];
        }

        $sorted = $curve;
        sort($sorted);
        $top = $sorted[min($n - 1, (int) floor(0.99 * ($n - 1)))];
        if ($top <= -79) {   // the service's floor is -80: nothing in this lane
            return array_fill(0, min($n, $maxColumns), 0.0);
        }

        $columns = min($n, $maxColumns);
        $floor = $top - self::RANGE_DB;
        $heights = [];
        for ($c = 0; $c < $columns; $c++) {
            $from = (int) floor($c * $n / $columns);
            $to = max($from + 1, (int) floor(($c + 1) * $n / $columns));
            $peak = max(array_slice($curve, $from, $to - $from));
            $heights[] = round(max(0.0, min(1.0, ($peak - $floor) / self::RANGE_DB)), 3);
        }

        return $heights;
    }

    /** The SVG path of a lane (viewBox `0 0 {columns} {height}`): a filled area on the baseline. */
    public static function path(array $heights, int $height = 100): string
    {
        if ($heights === []) {
            return '';
        }

        $d = "M0 {$height}";
        foreach ($heights as $i => $h) {
            $y = round($height * (1 - $h), 1);
            $d .= sprintf('H%d V%s', $i, $y).sprintf('H%d', $i + 1);
        }

        return $d.sprintf('V%dZ', $height);
    }

    /** "51 %" from a 0-1 ratio. */
    public static function percent(float $ratio): string
    {
        return round($ratio * 100).' %';
    }
}
