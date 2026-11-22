<?php

namespace App\Domain\Analysis;

use App\Domain\Helpers\TimeHelper;

/**
 * What the views draw of a stored mood curve (`file_analyses.mood_curve`, workstream 16.3): the curve reduced to at
 * most MAX_COLUMNS columns, the opacity of a column, and the mean of each section ("this part is more intense than that
 * one"). Arousal is how intense or energetic the music is (calm to aggressive), valence how positive it sounds; both are
 * integers 0-100 from a model's estimate.
 */
final class MoodCurve
{
    public const MAX_COLUMNS = 300;

    /**
     * The curve reduced to at most `$max` columns, each the mean of the points it covers.
     *
     * @param  list<int|float>  $values
     * @return list<int>
     */
    public static function columns(array $values, int $max = self::MAX_COLUMNS): array
    {
        $n = count($values);
        if ($n === 0) {
            return [];
        }

        $columns = min($n, max(1, $max));
        $out = [];
        for ($c = 0; $c < $columns; $c++) {
            $from = (int) floor($c * $n / $columns);
            $to = max($from + 1, (int) floor(($c + 1) * $n / $columns));
            $slice = array_slice($values, $from, $to - $from);
            $out[] = (int) round(array_sum($slice) / count($slice));
        }

        return $out;
    }

    /** The opacity of a column (0-100): faint when calm, strong when intense, never invisible. */
    public static function opacity(int|float $value): float
    {
        return round(0.12 + 0.78 * max(0.0, min(100.0, (float) $value)) / 100, 2);
    }

    /**
     * The mean arousal and valence of each section.
     *
     * @param  array{window: float, arousal: list<int>, valence: list<int>}  $curve
     * @param  list<array{start: float, end: float, label: ?string}>  $sections
     * @return list<array{start: float, end: float, label: ?string, arousal: int, valence: int}>
     */
    public static function perSection(array $curve, array $sections): array
    {
        $window = max(0.1, (float) $curve['window']);
        $out = [];

        foreach ($sections as $s) {
            $from = (int) floor((float) $s['start'] / $window);
            $to = max($from + 1, (int) ceil((float) $s['end'] / $window));
            $a = array_slice($curve['arousal'], $from, $to - $from);
            $v = array_slice($curve['valence'], $from, $to - $from);
            if ($a === [] || $v === []) {
                continue;
            }
            $out[] = ['start' => (float) $s['start'], 'end' => (float) $s['end'], 'label' => $s['label'] ?? null,
                'arousal' => (int) round(array_sum($a) / count($a)), 'valence' => (int) round(array_sum($v) / count($v))];
        }

        return $out;
    }

    /** A plain sentence about the most and least intense section, null when there are fewer than two or they hardly differ. */
    public static function summary(array $perSection): ?string
    {
        if (count($perSection) < 2) {
            return null;
        }

        $sorted = $perSection;
        usort($sorted, fn ($a, $b) => $b['arousal'] <=> $a['arousal']);
        $most = $sorted[0];
        $least = $sorted[count($sorted) - 1];
        if ($most['arousal'] - $least['arousal'] < 10) {
            return null;
        }

        $name = fn (array $s) => ($s['label'] !== null ? $s['label'].' ' : '').'('.TimeHelper::secondsToMinutes((int) $s['start']).' – '.TimeHelper::secondsToMinutes((int) $s['end']).')';

        return 'Most intense: '.$name($most).'. Calmest: '.$name($least).'.';
    }
}
