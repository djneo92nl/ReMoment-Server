<?php

namespace App\Domain\Analysis;

/**
 * The Camelot wheel notation of a key (`8A` = A minor, `8B` = C major), for harmonic mixing: two tracks mix well
 * when their codes are the same, one step apart on the wheel, or the same number with the other letter (relative
 * major/minor). Keys are the sharps-only names `file_analyses.key` holds, with `mode` major or minor.
 */
final class Camelot
{
    /** Camelot number by key, per mode (`B` = major, `A` = minor). */
    private const MAJOR = ['B' => 1, 'F#' => 2, 'C#' => 3, 'G#' => 4, 'D#' => 5, 'A#' => 6, 'F' => 7, 'C' => 8, 'G' => 9, 'D' => 10, 'A' => 11, 'E' => 12];

    private const MINOR = ['G#' => 1, 'D#' => 2, 'A#' => 3, 'F' => 4, 'C' => 5, 'G' => 6, 'D' => 7, 'A' => 8, 'E' => 9, 'B' => 10, 'F#' => 11, 'C#' => 12];

    /** The code of a key and mode, null when either is missing or unknown. */
    public static function code(?string $key, ?string $mode): ?string
    {
        $number = match ($mode) {
            'major' => self::MAJOR[$key] ?? null,
            'minor' => self::MINOR[$key] ?? null,
            default => null,
        };

        return $number === null ? null : $number.($mode === 'major' ? 'B' : 'A');
    }

    /** @return array{key: string, mode: string}|null the key and mode of a code such as `8A`, null when it is not one */
    public static function parse(?string $code): ?array
    {
        if ($code === null || !preg_match('/^(\d{1,2})([AB])$/', strtoupper($code), $m)) {
            return null;
        }

        $table = $m[2] === 'B' ? self::MAJOR : self::MINOR;
        $key = array_search((int) $m[1], $table, true);

        return $key === false ? null : ['key' => $key, 'mode' => $m[2] === 'B' ? 'major' : 'minor'];
    }

    /** Every code, `1A` … `12B`, with the key it stands for (`8A` => `8A · A minor`). @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (range(1, 12) as $number) {
            foreach (['A', 'B'] as $letter) {
                $parsed = self::parse($number.$letter);
                $options[$number.$letter] = "{$number}{$letter} · {$parsed['key']} {$parsed['mode']}";
            }
        }

        return $options;
    }

    /** The codes that mix well with $code, itself first: same, one step either way on the wheel, and the relative key. @return list<string> */
    public static function compatible(string $code): array
    {
        $parsed = self::parse($code);
        if ($parsed === null) {
            return [];
        }

        $number = (int) substr($code, 0, -1);
        $letter = strtoupper(substr($code, -1));
        $other = $letter === 'A' ? 'B' : 'A';

        return [
            $number.$letter,
            (($number + 10) % 12 + 1).$letter,
            ($number % 12 + 1).$letter,
            $number.$other,
        ];
    }
}
