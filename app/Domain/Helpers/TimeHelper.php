<?php

namespace App\Domain\Helpers;

class TimeHelper
{
    /** "1h 5m" from an hour up, else "5m": for totals like listening time. */
    public static function humanDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    public static function secondsToMinutes(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }
}
