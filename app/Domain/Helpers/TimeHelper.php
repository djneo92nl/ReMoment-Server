<?php

namespace App\Domain\Helpers;

class TimeHelper
{
    public static function secondsToMinutes(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }
}
