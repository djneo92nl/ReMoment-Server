<?php

namespace App\Domain\Radio;

use App\Domain\Library\LibraryIdentity;
use App\Models\Media\Track;
use App\Models\RadioPlay;
use App\Models\RadioStation;

/**
 * The songs a station plays, written when the polled title changes. Only ICY titles are songs: a show title
 * (NTS) is not logged. Whether a device or nobody listens makes no difference.
 */
class RadioPlayLog
{
    /** Plays older than this are pruned by the daily schedule. */
    public const KEEP_DAYS = 90;

    public static function record(RadioStation $station, string $source, string $raw): ?RadioPlay
    {
        if ($source !== 'icy') {
            return null;
        }

        [$artist, $title] = IcyReader::split($raw);

        $last = RadioPlay::where('radio_station_id', $station->id)->latest('started_at')->latest('id')->first();

        if ($last !== null && $last->artist === $artist && $last->title === $title) {
            return null;
        }

        return RadioPlay::create([
            'radio_station_id' => $station->id,
            'artist' => $artist,
            'title' => $title,
            'source' => $source,
            'started_at' => now(),
        ]);
    }

    /** The library track a play is, when the library has it. */
    public static function libraryTrack(RadioPlay $play): ?Track
    {
        return $play->artist === null ? null : LibraryIdentity::lookupTrack($play->artist, null, $play->title, null, null, null);
    }

    public static function prune(): int
    {
        return RadioPlay::where('started_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
