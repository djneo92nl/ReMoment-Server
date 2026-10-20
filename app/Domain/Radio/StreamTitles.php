<?php

namespace App\Domain\Radio;

use App\Jobs\PollRadioTitle;
use App\Models\RadioStation;
use Illuminate\Support\Facades\Cache;

/**
 * The song a station's stream says it plays, for renderers that show only the stream (generic UPnP).
 * One poll chain per station, whoever listens: it runs while some listener keeps calling {@see self::touch()}.
 */
final class StreamTitles
{
    /** A listener not heard from for this long ends the poll chain. */
    private const ACTIVE_SECONDS = 90;

    public static function get(int $stationId): ?string
    {
        return Cache::get("radio:title:{$stationId}");
    }

    public static function put(int $stationId, ?string $title): void
    {
        $title === null ? Cache::forget("radio:title:{$stationId}") : Cache::put("radio:title:{$stationId}", $title, now()->addMinutes(10));
    }

    /** A device plays the station: keep its title fresh, starting the poll chain when none runs. */
    public static function touch(RadioStation $station): void
    {
        Cache::put("radio:active:{$station->id}", true, self::ACTIVE_SECONDS);

        if (Cache::add("radio:polling:{$station->id}", true, self::ACTIVE_SECONDS * 2)) {
            PollRadioTitle::dispatch($station->id);
        }
    }

    public static function active(int $stationId): bool
    {
        return (bool) Cache::get("radio:active:{$stationId}");
    }

    public static function chainEnded(int $stationId): void
    {
        Cache::forget("radio:polling:{$stationId}");
        Cache::forget("radio:title:{$stationId}");
    }

    /** The chain is alive: its lock must outlive the delay to the next poll. */
    public static function chainContinues(int $stationId): void
    {
        Cache::put("radio:polling:{$stationId}", true, self::ACTIVE_SECONDS * 2);
    }
}
