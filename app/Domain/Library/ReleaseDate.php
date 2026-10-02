<?php

namespace App\Domain\Library;

final class ReleaseDate
{
    /** `2011`, `2011-03` or `2011-03-15` to a full date, so it fits `albums.released_at`; null if it isn't one. */
    public static function parse(?string $raw): ?string
    {
        if (!preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', trim((string) $raw), $m) || (int) $m[1] < 1000) {
            return null;
        }

        $month = (int) ($m[2] ?? 1) ?: 1;
        $day = (int) ($m[3] ?? 1) ?: 1;

        return checkdate($month, $day, (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $month, $day) : null;
    }
}
