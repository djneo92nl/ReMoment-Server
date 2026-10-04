<?php

namespace App\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\DeviceStateChanged;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Sonos\MusicPlayerDriver as SonosDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Puts a device on its default input (device_meta `default_source`, e.g. a
 * soundbar's TV) when it wakes. Playback the server or a person started is
 * left alone: a track or station already playing means the wake was
 * deliberate, so only a wake without content switches. Queued, because it
 * talks to the device and the event fires inside the listener's poll loop.
 */
class SwitchToDefaultSource implements ShouldQueue
{
    public const META_KEY = 'default_source';

    /** Only a wake of a device with a default input is worth a job. */
    public function shouldQueue(DeviceStateChanged $event): bool
    {
        $woke = in_array($event->state, [State::Playing, State::Paused], true)
            && in_array($event->previous, [null, State::Standby, State::Unreachable], true);

        return $woke && $this->defaultSource($event->deviceId) !== null;
    }

    public function handle(DeviceStateChanged $event): void
    {
        $sourceId = $this->defaultSource($event->deviceId);
        if ($sourceId === null) {
            return;
        }

        $device = Device::find($event->deviceId);
        if ($device === null) {
            return;
        }

        $nowPlaying = DeviceCache::getNowPlaying($event->deviceId);
        if ($nowPlaying?->track !== null || $nowPlaying?->radio !== null) {
            return;
        }

        try {
            $driver = $device->driver;
            if (!($driver instanceof SourceActivationInterface)) {
                return;
            }

            if ($driver instanceof SonosDriver && $driver->activeInput() === $sourceId) {
                return;
            }

            $driver->activateSource($sourceId);
        } catch (\Throwable $e) {
            Log::warning("Could not switch device {$event->deviceId} to its default source '{$sourceId}': {$e->getMessage()}");
        }
    }

    private function defaultSource(int $deviceId): ?string
    {
        // Runs in the device listener's poll loop: a database hiccup must not stop it.
        try {
            return DeviceMeta::where('device_id', $deviceId)->where('key', self::META_KEY)->value('value') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
