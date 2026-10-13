<?php

namespace App\Domain\Analysis;

use Illuminate\Support\Facades\Process;

/** The command line tools the analysis stages shell out to. */
final class Tools
{
    /** @var array<string, string|null> */
    private static array $versions = [];

    public static function ffmpeg(): string
    {
        return config('analysis.ffmpeg');
    }

    public static function fpcalc(): string
    {
        return config('analysis.fpcalc');
    }

    /** The first line of `<binary> <flag>`, or null when it can't be run. Remembered for the process. */
    public static function version(string $binary, string $flag = '-version'): ?string
    {
        return self::$versions[$binary.$flag] ??= self::probe($binary, $flag);
    }

    public static function available(string $binary, string $flag = '-version'): bool
    {
        return self::version($binary, $flag) !== null;
    }

    public static function forget(): void
    {
        self::$versions = [];
    }

    private static function probe(string $binary, string $flag): ?string
    {
        try {
            $result = Process::timeout(10)->run([$binary, $flag]);
        } catch (\Throwable) {
            return null;
        }

        if (!$result->successful()) {
            return null;
        }

        $line = trim(strtok($result->output() ?: $result->errorOutput(), "\n"));

        return $line !== '' ? $line : 'available';
    }
}
