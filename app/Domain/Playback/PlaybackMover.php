<?php

namespace App\Domain\Playback;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Models\Device;
use App\Models\Media\Track;

/**
 * "Move playback to…": what device A plays continues on device B (see PlaybackCapture for what can move).
 *
 * - Spotify: Spotify's own transfer to B's Connect device; queue and position come along.
 * - Radio: the station starts on B.
 * - Library tracks: the queue starts on B at the current track (tracks B can't play are left out), then B
 *   seeks to the position once it plays. Seeking is whole seconds, as the devices report them.
 *
 * A is stopped afterwards unless `$stopSource` is false, and only once B really started: a move that fails
 * leaves A playing.
 */
final class PlaybackMover
{
    public function __construct(
        private readonly PlaybackCapture $capture,
        private readonly LibraryPlayback $library,
    ) {}

    /** Whether something movable plays on $device (the `move_playback` capability); cache only, see PlaybackCapture::movable(). */
    public function canMove(Device $device): bool
    {
        return $this->capture->movable($device);
    }

    /**
     * @param  \Closure|null  $position  seconds to seek to, asked once the target plays (a sync group follows the
     *                                   leader's position of that moment, not of the capture); default the captured one
     * @return array{kind: string, queued: int, skipped: int, position: int}
     *
     * @throws NotMovableException what plays on $from can't move
     * @throws NotPlayableException $to can't play it
     * @throws PlaybackFailedException $to or Spotify failed
     */
    public function move(Device $from, Device $to, bool $stopSource = true, ?\Closure $position = null): array
    {
        if ($from->id === $to->id) {
            throw new NotMovableException('Playback is already on that device.');
        }

        $snapshot = $this->capture->capture($from);

        $result = match ($snapshot->kind) {
            PlaybackSnapshot::SPOTIFY => $this->moveSpotify($to, $snapshot),
            PlaybackSnapshot::RADIO => $this->moveRadio($to, $snapshot),
            default => $this->moveLibrary($to, $snapshot, $position),
        };

        // Spotify's transfer already took playback away from A.
        if ($stopSource && $snapshot->kind !== PlaybackSnapshot::SPOTIFY) {
            $this->stop($from);
        }

        return $result + ['kind' => $snapshot->kind];
    }

    private function moveSpotify(Device $to, PlaybackSnapshot $snapshot): array
    {
        $this->library->transferSpotify($to, play: !$snapshot->paused);

        return ['queued' => 0, 'skipped' => 0, 'position' => $snapshot->position];
    }

    private function moveRadio(Device $to, PlaybackSnapshot $snapshot): array
    {
        $station = $snapshot->station;
        $driver = $to->driver;

        if (!($driver instanceof RadioControlInterface) || !$driver->canPlayRadioStation($station)) {
            throw new NotPlayableException("{$station->name} can't be played on {$to->device_name}.");
        }

        try {
            $driver->playRadioStation($station);
        } catch (\Throwable $e) {
            throw new PlaybackFailedException("{$to->device_name} did not start the station: ".$e->getMessage(), previous: $e);
        }

        $station->markPlayed();

        return ['queued' => 1, 'skipped' => 0, 'position' => 0];
    }

    private function moveLibrary(Device $to, PlaybackSnapshot $snapshot, ?\Closure $position = null): array
    {
        $tracks = Track::with('metadata', 'artist', 'album')->whereIn('id', $snapshot->trackIds)->get()->keyBy('id');
        $ordered = collect($snapshot->trackIds)->map(fn (int $id) => $tracks[$id] ?? null)->filter()->values();

        $result = $this->library->playTracks($to, $ordered, $ordered->first());

        // Only the first track's position means something, and only when the target plays that very track.
        $firstPlays = $this->playsOn($to, $ordered->first(), $result->via);

        $seekTo = $snapshot->position;

        if ($firstPlays && ($snapshot->position > 0 || $position !== null) && $this->waitUntilPlaying($to)) {
            $seekTo = max(0, (int) ($position ? $position() : $snapshot->position));
            $this->seek($to, $seekTo);
        }

        if ($snapshot->paused) {
            $this->pause($to);
        }

        return [
            'queued' => $result->playable,
            'skipped' => $ordered->count() - $result->playable,
            'position' => $firstPlays ? $seekTo : 0,
        ];
    }

    private function playsOn(Device $device, Track $track, string $via): bool
    {
        return $via === 'spotify' ? LibraryPlayback::spotifyUri($track) !== null : (bool) $track->getDlnaUrl();
    }

    /** Waits (up to `playback.move_wait_seconds`) for the target's listener to report it playing. */
    private function waitUntilPlaying(Device $device): bool
    {
        $deadline = microtime(true) + max(0, (int) config('playback.move_wait_seconds'));

        do {
            if (DeviceCache::getState($device->id) === State::Playing) {
                return true;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        return DeviceCache::getState($device->id) === State::Playing;
    }

    private function seek(Device $device, int $seconds): void
    {
        $driver = SpotifyRouting::driverFor($device, SeekInterface::class);

        if ($driver instanceof SeekInterface) {
            try {
                $driver->seek($seconds);
            } catch (\Throwable) {
                // Best effort: it plays, just from the start of the track.
            }
        }
    }

    private function pause(Device $device): void
    {
        $driver = SpotifyRouting::driverFor($device, MediaControlsInterface::class);

        try {
            $driver->pause();
        } catch (\Throwable) {
            // Best effort.
        }
    }

    private function stop(Device $device): void
    {
        $driver = SpotifyRouting::driverFor($device, MediaControlsInterface::class);

        if ($driver instanceof MediaControlsInterface) {
            try {
                $driver->stop();
            } catch (\Throwable) {
                // Best effort: both play; the user can stop it.
            }
        }
    }
}
