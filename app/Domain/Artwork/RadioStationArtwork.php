<?php

namespace App\Domain\Artwork;

use App\Jobs\ProcessArtwork;
use App\Models\RadioStation;
use Illuminate\Support\Facades\Cache;

/**
 * Artwork for a station in a station list (GET /api/devices/{id}/radio): its
 * image_url from /radio, else the image last shown while it played, else the generic radio logo. Never waits for a
 * download — an unprocessed image is null and queued once an hour at most.
 */
final class RadioStationArtwork
{
    public static function resolve(RadioStation $station): ?array
    {
        $url = trim((string) $station->image_url) ?: self::playedImageUrl($station);

        if ($url === '') {
            $logo = NowPlayingArtwork::logo('radio');

            return $logo === null ? null : ['kind' => NowPlayingArtwork::KIND_RADIO] + $logo;
        }

        if (!ArtworkCache::has($url) && Cache::add('artwork:radio-station-queued:'.md5($url), true, now()->addHour())) {
            ProcessArtwork::dispatch($url);
        }

        $entry = ArtworkCache::get($url);

        return is_array($entry) ? ['kind' => NowPlayingArtwork::KIND_RADIO] + $entry : null;
    }

    /** The station image its stream reported the last time it played. */
    private static function playedImageUrl(RadioStation $station): string
    {
        return trim((string) $station->plays()->whereNotNull('image_url')->where('image_url', '!=', '')->latest('played_at')->value('image_url'));
    }
}
