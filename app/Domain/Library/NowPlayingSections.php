<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;
use Illuminate\Support\Facades\Cache;

class NowPlayingSections
{
    /**
     * The stored sections of the playing track when the admin asked for them (`Track::sections()`), else null. Looked up
     * through the library like the waveform; remembered for a minute so a refresh costs no queries and a new or removed
     * result shows up quickly.
     *
     * @return array{sections: list<array<string, mixed>>, reason: ?string, method: string}|null
     */
    public static function for(?NowPlaying $nowPlaying): ?array
    {
        $track = $nowPlaying?->track;
        $name = is_string($track?->name) ? trim($track->name) : '';
        $artist = $track?->artist?->name;
        if ($name === '' || !is_string($artist) || $artist === '') {
            return null;
        }

        $key = 'now_playing_sections_'.md5(implode('|', [$artist, $nowPlaying->album?->name, $name, (int) $track->duration]));
        $found = Cache::get($key);
        if ($found === null) {
            try {
                $found = LibraryIdentity::lookupTrack($artist, $nowPlaying->album?->name, $name, $track->id, $track->source, $track->duration)?->sections();
            } catch (\Throwable) {
                $found = null;
            }
            Cache::put($key, $found ?? false, 60);
        }

        return $found ?: null;
    }
}
