<?php

namespace App\Domain\Playback;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceVolume;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Jobs\RestoreAfterAnnouncement;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * An announcement: play a short clip on a device, then put back what it was doing (docs/api/announcements.md).
 *
 * Before the clip the device's state is remembered (what plays, where in the track, volume, standby). Nothing
 * tells the server that a clip has ended, so the restore is a job delayed by the clip's length. A second
 * announcement during the first keeps the first one's memory (otherwise the clip itself would be "what was
 * playing") and only the last restore job acts.
 */
final class Announcer
{
    /** How long after the clip's end the restore runs: speakers buffer before they start. */
    public const SETTLE_MS = 1500;

    private const MEMORY_SECONDS = 300;

    public function __construct(
        private readonly PlaybackCapture $capture,
        private readonly PlaybackMover $mover,
    ) {}

    /**
     * @param  array{name: string, label: string, duration_ms: int}  $clip
     *
     * @throws \InvalidArgumentException the device cannot play a clip
     * @throws \Throwable the device did not take it
     */
    public function announce(Device $device, array $clip, ?int $volume = null): void
    {
        $driver = $device->driver;
        if (!($driver instanceof PlayStreamUrlInterface)) {
            throw new \InvalidArgumentException("{$device->device_name} cannot play an announcement.");
        }

        $memory = Cache::get(self::key($device->id)) ?? $this->remember($device);
        $memory['run'] = (string) Str::uuid();
        Cache::put(self::key($device->id), $memory, self::MEMORY_SECONDS);

        if ($memory['kind'] === PlaybackSnapshot::SPOTIFY) {
            // Spotify plays from the cloud: pause it there, or it takes the speaker back during the clip.
            $this->quietly(fn () => SpotifyRouting::driverFor($device, MediaControlsInterface::class)->pause());
        }

        if ($volume !== null) {
            $this->quietly(fn () => DeviceVolume::set($device, $volume));
        }

        try {
            $driver->playStreamUrl(AnnouncementClips::url($clip['name']), $clip['label']);
        } catch (\Throwable $e) {
            $this->restore($device, $memory['run']);

            throw $e;
        }

        RestoreAfterAnnouncement::dispatch($device->id, $memory['run'])
            ->delay(now()->addMilliseconds($clip['duration_ms'] + self::SETTLE_MS));
    }

    /** Put the device back as it was before the announcement; does nothing when a later announcement took over. */
    public function restore(Device $device, string $run): bool
    {
        $memory = Cache::get(self::key($device->id));
        if (!is_array($memory) || ($memory['run'] ?? null) !== $run) {
            return false;
        }
        Cache::forget(self::key($device->id));

        if ($memory['volume'] !== null) {
            $this->quietly(fn () => DeviceVolume::set($device, $memory['volume']));
        }

        try {
            match ($memory['kind']) {
                PlaybackSnapshot::LIBRARY, PlaybackSnapshot::RADIO, PlaybackSnapshot::SPOTIFY => $this->mover->apply($device, $this->snapshot($memory)),
                'source' => $this->toSource($device, $memory['source_id']),
                default => $this->toSilence($device, $memory['standby']),
            };
        } catch (\Throwable $e) {
            Log::warning("Playback of device {$device->id} was not restored after an announcement: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    /** @return array{kind: string, position: int, paused: bool, track_ids: int[], station_id: ?int, source_id: ?string, volume: ?int, standby: bool} */
    private function remember(Device $device): array
    {
        $volume = Volume::getVolume($device->id);
        $memory = [
            'kind' => 'none', 'position' => 0, 'paused' => false, 'track_ids' => [], 'station_id' => null, 'source_id' => null,
            'volume' => $volume === false ? null : $volume,
            'standby' => DeviceCache::getState($device->id) === State::Standby,
        ];

        try {
            $snapshot = $this->capture->capture($device);

            return ['kind' => $snapshot->kind, 'position' => $snapshot->position, 'paused' => $snapshot->paused, 'track_ids' => $snapshot->trackIds, 'station_id' => $snapshot->station?->id] + $memory;
        } catch (NotMovableException) {
            // Nothing playing, or an input of the device itself (line-in, TV): go back to that input when it is known.
            $source = $this->activeSourceId($device);

            return $source !== null ? ['kind' => 'source', 'source_id' => $source] + $memory : $memory;
        }
    }

    private function snapshot(array $memory): PlaybackSnapshot
    {
        return new PlaybackSnapshot(
            $memory['kind'], $memory['position'], $memory['paused'], $memory['track_ids'],
            $memory['station_id'] ? RadioStation::find($memory['station_id']) : null,
        );
    }

    private function activeSourceId(Device $device): ?string
    {
        $source = DeviceCache::getNowPlaying($device->id)?->source;
        if ($source === null || !in_array(DeviceCache::getState($device->id), [State::Playing, State::Paused], true)) {
            return null;
        }

        return $device->deviceSources()
            ->where(fn ($q) => $q->where('friendly_name', $source->name)->orWhere('source_type', $source->sourceType))
            ->orderByRaw('friendly_name = ? desc', [$source->name])
            ->value('source_id');
    }

    private function toSource(Device $device, string $sourceId): void
    {
        $driver = $device->driver;
        if ($driver instanceof SourceActivationInterface) {
            $driver->activateSource($sourceId);
        }
    }

    private function toSilence(Device $device, bool $wasStandby): void
    {
        $driver = $device->driver;
        if ($wasStandby && $driver instanceof PowerInterface) {
            $driver->standby();
        } elseif ($driver instanceof MediaControlsInterface) {
            $driver->stop();
        }
    }

    private function quietly(\Closure $do): void
    {
        try {
            $do();
        } catch (\Throwable) {
            // Best effort: the announcement matters more than this step.
        }
    }

    private static function key(int $deviceId): string
    {
        return "announce:{$deviceId}";
    }
}
