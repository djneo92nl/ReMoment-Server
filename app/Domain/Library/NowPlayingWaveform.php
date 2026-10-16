<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;
use Illuminate\Support\Facades\Cache;

class NowPlayingWaveform
{
    /**
     * The waveform (peaks 0-100) of the playing track when the library has its own audio file analysed, else null.
     * Looked up through the library like play history does; remembered briefly so a refresh costs no queries.
     *
     * @return list<int>|null
     */
    public static function for(?NowPlaying $nowPlaying): ?array
    {
        $track = $nowPlaying?->track;
        $name = is_string($track?->name) ? trim($track->name) : '';
        $artist = $track?->artist?->name;
        if ($name === '' || !is_string($artist) || $artist === '') {
            return null;
        }

        $key = 'player_bar_waveform_'.md5(implode('|', [$artist, $nowPlaying->album?->name, $name, (int) $track->duration]));
        $points = Cache::get($key);
        if ($points === null) {
            try {
                $points = LibraryIdentity::lookupTrack($artist, $nowPlaying->album?->name, $name, $track->id, $track->source, $track->duration)?->waveform();
            } catch (\Throwable) {
                $points = null;
            }
            // An analysis can arrive later: a miss is remembered only briefly
            Cache::put($key, $points ?? false, $points ? 3600 : 120);
        }

        return $points ?: null;
    }
}
