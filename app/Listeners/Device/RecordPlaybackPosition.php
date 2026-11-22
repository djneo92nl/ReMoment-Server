<?php

namespace App\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyUri;
use App\Domain\Media\NowPlaying;
use App\Domain\Profile\Profile;
use App\Domain\Resume\PlaybackPositions;
use App\Domain\Resume\ResumeOnPlay;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\ProgressUpdated;
use App\Models\PlaybackPosition;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the resume position (workstream 8.3, docs/api/playback-positions.md): the progress a device reports for a long
 * track or a podcast episode becomes its bookmark, and finishing it clears the bookmark.
 *
 * Cheap on purpose, it runs for every progress report of every device: the item is resolved once per now-playing track
 * and remembered, the last position is kept in the cache, and the database is written at most every few seconds.
 */
class RecordPlaybackPosition
{
    public function handle(ProgressUpdated|NowPlayingEnded $event): void
    {
        $deviceId = (int) $event->deviceId;

        if ($deviceId <= 0 || !Profile::allows('library')) {
            return;
        }

        if ($event instanceof NowPlayingEnded) {
            $this->ended($deviceId);

            return;
        }

        if (ResumeOnPlay::pending($deviceId)) {
            return; // the seek to the bookmark is still due: a report of the first seconds must not overwrite it
        }

        $nowPlaying = DeviceCache::getNowPlaying($deviceId);
        $subject = $this->subject($deviceId, $nowPlaying);

        if ($subject === null) {
            return;
        }

        [$kind, $itemId, $duration] = $subject;
        Cache::put($this->lastKey($deviceId), [$kind, $itemId, $event->progress, $duration], 600);

        // The end is never waited for: a finish clears the bookmark at once.
        if (PlaybackPositions::finished($event->progress, $duration)) {
            PlaybackPositions::finish($kind, $itemId);

            return;
        }

        // The first seconds keep nothing, and must not use up the save interval either.
        if ($event->progress < (int) config('resume.min_seconds') || !Cache::add("resume:throttle:{$deviceId}", 1, (int) config('resume.save_interval'))) {
            return;
        }

        PlaybackPositions::save($kind, $itemId, $event->progress, $duration, $deviceId);
    }

    /** The device stopped or went to standby: a stop inside the tail of the item is a finish. */
    private function ended(int $deviceId): void
    {
        $last = Cache::pull($this->lastKey($deviceId));

        // Not an item the server started a moment ago: the end of what played before it can arrive late.
        if (($started = ResumeOnPlay::subject($deviceId)) && time() - $started['at'] > 15) {
            ResumeOnPlay::forget($deviceId);
        }

        if (is_array($last)) {
            [$kind, $itemId, $position, $duration] = $last;

            if (PlaybackPositions::finished((int) $position, $duration)) {
                PlaybackPositions::finish($kind, $itemId);
            }
        }
    }

    /** @return array{0: string, 1: int, 2: ?int}|null kind, item id, duration */
    private function subject(int $deviceId, ?NowPlaying $nowPlaying): ?array
    {
        $name = $nowPlaying?->track?->name;

        // An episode the server started: kept while the device still shows it (or shows no title at all).
        if ($started = ResumeOnPlay::subject($deviceId)) {
            if ($name === null || $name === '' || Normalizer::track($name) === Normalizer::track($started['title'])) {
                return [$started['kind'], $started['itemId'], $nowPlaying?->track?->duration ?? $started['duration']];
            }
            ResumeOnPlay::forget($deviceId);
        }

        $track = $nowPlaying?->track;
        if ($track === null || trim((string) $track->name) === '') {
            return null;
        }

        $signature = sha1(implode('|', [$track->artist?->name, $nowPlaying->album?->name, $track->name]));
        $found = Cache::remember("resume:track:{$deviceId}:{$signature}", 3600, function () use ($nowPlaying, $track) {
            $library = LibraryIdentity::lookupTrack(
                trim((string) $track->artist?->name),
                $nowPlaying->album?->name,
                trim((string) $track->name),
                $track->id ?? SpotifyUri::fromMeta($track->meta ?? [], $track->source),
                $track->source,
                $track->duration,
            );

            return $library !== null && PlaybackPositions::eligible($library)
                ? ['id' => $library->id, 'duration' => $library->duration ?: $track->duration]
                : ['id' => 0, 'duration' => null];
        });

        return $found['id'] > 0 ? [PlaybackPosition::TRACK, $found['id'], $found['duration']] : null;
    }

    private function lastKey(int $deviceId): string
    {
        return "resume:last:{$deviceId}";
    }
}
