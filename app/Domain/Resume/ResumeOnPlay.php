<?php

namespace App\Domain\Resume;

use App\Integrations\Contracts\SeekInterface;
use App\Jobs\ResumePlayback;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\PlaybackPosition;
use Illuminate\Support\Facades\Cache;

/**
 * Playing resumes (workstream 8.3): when the server starts a bookmarked track or episode, it seeks the device to the
 * bookmark a moment later, once the device reports the item. A request opts out with `resume=false` (play from the
 * start), which the web UI, the REST API and MCP all share because they all arrive as a request.
 */
final class ResumeOnPlay
{
    /** Seconds the device gets to start the item before the seek, and how long progress reports are ignored meanwhile. */
    public const DELAY = 3;

    /** The item the server started on a device that is not a library track (an episode): progress is kept for it. */
    private const SUBJECT_TTL = 86400;

    /** False when the request says `resume=false`; anything else (nothing, true) resumes. */
    public static function wanted(): bool
    {
        if (!app()->bound('request')) {
            return true;
        }

        return filter_var(request()->input('resume', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Schedules the seek for the track that now starts on $device. Returns the second it continues from, null when it
     * starts from the beginning (no bookmark, resume off, or a device that cannot seek).
     */
    public static function afterTrack(Device $device, ?Track $track): ?int
    {
        if ($track === null || !self::wanted()) {
            return null;
        }

        return self::schedule($device, PlaybackPosition::TRACK, $track->id, $track->name);
    }

    /** The same for a podcast episode; also remembers it as what plays on the device so its progress is kept. */
    public static function afterEpisode(Device $device, int $episodeId, string $title, ?int $duration): ?int
    {
        self::remember($device->id, PlaybackPosition::EPISODE, $episodeId, $title, $duration);

        if (!self::wanted()) {
            return null;
        }

        return self::schedule($device, PlaybackPosition::EPISODE, $episodeId, $title);
    }

    /** What the server started on a device that the library cannot find by its now-playing: an episode. */
    public static function remember(int $deviceId, string $kind, int $itemId, string $title, ?int $duration): void
    {
        Cache::put(self::subjectKey($deviceId), ['kind' => $kind, 'itemId' => $itemId, 'title' => $title, 'duration' => $duration, 'at' => time()], self::SUBJECT_TTL);
    }

    /** @return array{kind: string, itemId: int, title: string, duration: ?int, at: int}|null */
    public static function subject(int $deviceId): ?array
    {
        return Cache::get(self::subjectKey($deviceId));
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::subjectKey($deviceId));
    }

    /** Whether a seek for the device is still due: progress reports inside it must not overwrite the bookmark. */
    public static function pending(int $deviceId): bool
    {
        return Cache::has(self::pendingKey($deviceId));
    }

    public static function done(int $deviceId): void
    {
        Cache::forget(self::pendingKey($deviceId));
    }

    private static function schedule(Device $device, string $kind, int $itemId, string $title): ?int
    {
        if (!$device->driver instanceof SeekInterface) {
            return null;
        }

        $position = PlaybackPositions::secondsFor($kind, $itemId);
        if ($position <= 0) {
            return null;
        }

        Cache::put(self::pendingKey($device->id), true, 30);
        ResumePlayback::dispatch($device->id, $kind, $itemId, $title, $position)->delay(now()->addSeconds(self::DELAY));

        return $position;
    }

    private static function subjectKey(int $deviceId): string
    {
        return "resume:subject:{$deviceId}";
    }

    private static function pendingKey(int $deviceId): string
    {
        return "resume:pending:{$deviceId}";
    }
}
