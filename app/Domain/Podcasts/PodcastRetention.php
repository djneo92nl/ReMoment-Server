<?php

namespace App\Domain\Podcasts;

use App\Models\PlaybackPosition;
use App\Models\Podcast;

/** Retention of old episodes: the newest N of a podcast stay; an episode with a bookmark is never pruned. Rows only: no audio is stored. */
final class PodcastRetention
{
    /** Episodes kept for this podcast: its own setting, else the server's; 0 keeps all. */
    public static function limit(Podcast $podcast): int
    {
        return max(0, (int) ($podcast->keep_episodes ?? config('podcasts.keep_episodes')));
    }

    /** @return int episodes removed */
    public static function apply(Podcast $podcast): int
    {
        $keep = self::limit($podcast);

        if ($keep === 0) {
            return 0;
        }

        $old = $podcast->episodes()
            ->orderByRaw('published_at is null')->orderByDesc('published_at')->orderByDesc('id')
            ->skip($keep)->take(PHP_INT_MAX)->pluck('id');

        if ($old->isEmpty()) {
            return 0;
        }

        $bookmarked = PlaybackPosition::where('kind', PlaybackPosition::EPISODE)->whereIn('item_id', $old)->pluck('item_id');

        return $podcast->episodes()->whereIn('id', $old->diff($bookmarked))->delete();
    }
}
