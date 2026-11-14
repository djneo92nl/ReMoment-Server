<?php

namespace App\Domain\Stats;

use App\Models\Play;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * How long the play log is kept (docs/api/history.md). Default: forever. A set number of months lets the daily
 * `history:prune` delete older plays; tracks, albums and artists are never touched, only rows of `plays`.
 */
class PlayRetention
{
    public const SETTING = 'history_keep_months';

    public const MAX_MONTHS = 600;

    /** Months of history to keep, or null for forever. */
    public static function months(): ?int
    {
        $value = Setting::get(self::SETTING);

        return is_numeric($value) && (int) $value > 0 ? min((int) $value, self::MAX_MONTHS) : null;
    }

    public static function setMonths(?int $months): void
    {
        if ($months === null || $months < 1) {
            Setting::forget(self::SETTING);

            return;
        }

        Setting::set(self::SETTING, (string) min($months, self::MAX_MONTHS));
    }

    public static function cutoff(?int $months = null): ?Carbon
    {
        $months ??= self::months();

        return $months === null ? null : now()->subMonthsNoOverflow($months);
    }

    /** Plays that would go. */
    public static function expired(?int $months = null): int
    {
        $cutoff = self::cutoff($months);

        return $cutoff === null ? 0 : Play::where('played_at', '<', $cutoff)->count();
    }

    /** Deletes plays older than the cutoff in chunks (a long first run does not hold one big lock); returns how many went. */
    public static function prune(?int $months = null): int
    {
        $cutoff = self::cutoff($months);
        $deleted = 0;

        if ($cutoff === null) {
            return 0;
        }

        do {
            $ids = Play::where('played_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : Play::whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $deleted;
    }
}
