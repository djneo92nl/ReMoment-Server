<?php

namespace App\Domain\Resume;

use App\Models\Media\Track;
use App\Models\PlaybackPosition;
use Illuminate\Support\Collection;

/**
 * Resume position (workstream 8.3): a server-side bookmark per long track (audiobook chapter, DJ mix, concert) and per
 * podcast episode, so playing it again continues where it stopped. Household-wide: there are no accounts.
 *
 * The rule (config/resume.php): a track keeps a bookmark when it is at least `min_minutes` long or one of its genres says
 * audiobook or podcast; a podcast episode always does. Progress from a device is saved at most every `save_interval`
 * seconds, nothing in the first `min_seconds`, and the bookmark is cleared within `tail_seconds` of the end.
 */
final class PlaybackPositions
{
    /** Whether a track keeps a bookmark: long enough, or an audiobook or podcast by its genre. */
    public static function eligible(Track $track): bool
    {
        if ((int) $track->duration >= (int) config('resume.min_minutes') * 60) {
            return true;
        }

        $wanted = (array) config('resume.genres');

        foreach ($track->genres() as $genre) {
            if (in_array(mb_strtolower(trim($genre)), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    public static function find(string $kind, int $itemId): ?PlaybackPosition
    {
        return PlaybackPosition::where('kind', $kind)->where('item_id', $itemId)->first();
    }

    /** Seconds to continue from, 0 when there is no bookmark. */
    public static function secondsFor(string $kind, int $itemId): int
    {
        return (int) PlaybackPosition::where('kind', $kind)->where('item_id', $itemId)->value('position');
    }

    /** The bookmarked track among these (the most recently kept), for an album that plays from where it stopped. */
    public static function bookmarkedTrack(Collection $tracks): ?Track
    {
        if ($tracks->isEmpty()) {
            return null;
        }

        $id = PlaybackPosition::where('kind', PlaybackPosition::TRACK)->whereIn('item_id', $tracks->pluck('id'))
            ->where('position', '>', 0)->latest('updated_at')->value('item_id');

        return $id === null ? null : $tracks->firstWhere('id', $id);
    }

    /** Within the tail of the item (or past it): finished, no bookmark to keep. */
    public static function finished(int $position, ?int $duration): bool
    {
        return $duration !== null && $duration > 0 && $position >= $duration - (int) config('resume.tail_seconds');
    }

    /**
     * Keeps the position. A position in the first seconds is ignored, one in the tail clears the bookmark (the item finished).
     *
     * @return PlaybackPosition|null the bookmark, null when nothing was kept
     */
    public static function save(string $kind, int $itemId, int $position, ?int $duration = null, ?int $deviceId = null): ?PlaybackPosition
    {
        $position = max(0, $position);

        if (self::finished($position, $duration)) {
            self::clear($kind, $itemId);

            return null;
        }

        if ($position < (int) config('resume.min_seconds')) {
            return null;
        }

        return PlaybackPosition::updateOrCreate(
            ['kind' => $kind, 'item_id' => $itemId],
            ['position' => $position, 'duration' => $duration, 'device_id' => $deviceId],
        );
    }

    /** Sets a position by hand (an app that tracks playback itself), also in the first seconds and for any duration. */
    public static function set(string $kind, int $itemId, int $position, ?int $duration = null): ?PlaybackPosition
    {
        if ($position <= 0 || self::finished($position, $duration)) {
            self::clear($kind, $itemId);

            return null;
        }

        return PlaybackPosition::updateOrCreate(['kind' => $kind, 'item_id' => $itemId], ['position' => $position, 'duration' => $duration]);
    }

    public static function clear(string $kind, int $itemId): bool
    {
        return PlaybackPosition::where('kind', $kind)->where('item_id', $itemId)->delete() > 0;
    }
}
