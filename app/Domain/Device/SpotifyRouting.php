<?php

namespace App\Domain\Device;

use App\Domain\Device\Cache\Modes;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * While a mapped local speaker plays Spotify (Spotify Connect), it and the
 * Spotify virtual device behave as one device: the Spotify listener reports
 * the playback and its modes for the local device, playback commands on the
 * local device go to the Spotify driver, the local device reports Spotify's
 * playback capabilities on top of its own, and device lists drop the
 * Spotify virtual device. The routed device id is set by the Spotify
 * listener (`DeviceCache::setSpotifyRoutedDevice()`, 30 s TTL).
 */
final class SpotifyRouting
{
    /**
     * Contracts the Spotify driver handles for the routed speaker. Volume,
     * mute, power, sources, multiroom, radio and library playback stay with
     * the speaker's own driver.
     */
    public const DELEGATED_CONTRACTS = [
        MediaControlsInterface::class,
        SeekInterface::class,
        QueueInterface::class,
        QueueJumpInterface::class,
        ShuffleInterface::class,
        RepeatInterface::class,
        LikeInterface::class,
    ];

    public static function routedDeviceId(): ?int
    {
        $id = DeviceCache::getSpotifyRoutedDeviceId();

        return $id === null ? null : (int) $id;
    }

    public static function isSpotify(Device $device): bool
    {
        return is_a((string) $device->device_driver, SpotifyDriver::class, true);
    }

    /** True for the local (non-Spotify) device Spotify is currently playing on. */
    public static function isRoutedTarget(Device $device): bool
    {
        $routedId = self::routedDeviceId();

        return $routedId !== null && $routedId === $device->id && !self::isSpotify($device);
    }

    public static function spotifyDevice(): ?Device
    {
        return Device::where('device_driver', SpotifyDriver::class)->orderBy('id')->first();
    }

    /**
     * The device whose driver executes $contract for $device: the Spotify
     * virtual device for a delegated contract while $device is the routed
     * target, else $device itself.
     */
    public static function deviceFor(Device $device, string $contract): Device
    {
        if (in_array($contract, self::DELEGATED_CONTRACTS, true) && self::isRoutedTarget($device)) {
            return self::spotifyDevice() ?? $device;
        }

        return $device;
    }

    public static function driverFor(Device $device, string $contract): object
    {
        return self::deviceFor($device, $contract)->driver;
    }

    /**
     * The device a shuffle/repeat/like change made through $device is
     * reported for: while routed, Spotify's modes belong to the local
     * speaker, also when the change was requested on the Spotify device.
     */
    public static function modesDeviceId(Device $device): int
    {
        $routedId = self::routedDeviceId();

        if ($routedId !== null && (self::isRoutedTarget($device) || self::isSpotify($device))) {
            return $routedId;
        }

        return $device->id;
    }

    /**
     * API capabilities of $device: its own driver's, plus the Spotify
     * driver's playback capabilities while it is the routed target.
     *
     * @return string[]
     */
    public static function capabilities(Device $device): array
    {
        $own = Capabilities::forDriver($device->device_driver);

        if (!self::isRoutedTarget($device) || !($spotify = self::spotifyDevice())) {
            return $own;
        }

        $delegated = array_intersect(
            Capabilities::forDriver($spotify->device_driver),
            Capabilities::forContracts(self::DELEGATED_CONTRACTS),
        );

        return Capabilities::sort(array_merge($own, $delegated));
    }

    /**
     * Drops the Spotify virtual device from a device list while Spotify is
     * routed to a local device in that same list, so the speaker shows up
     * once. A list without the routed speaker (e.g. a client assigned only
     * the Spotify device) keeps the Spotify device.
     *
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, Device>
     */
    public static function visible(Collection $devices): Collection
    {
        $routedId = self::routedDeviceId();

        if ($routedId === null || !$devices->contains(fn (Device $d) => $d->id === $routedId && !self::isSpotify($d))) {
            return $devices;
        }

        return $devices->reject(fn (Device $d) => self::isSpotify($d))->values();
    }

    /**
     * Hands a device back its own modes once Spotify's modes stop being
     * reported for it: the values its own listener last reported, or all
     * null when its driver reports none, so clients hide the buttons.
     */
    public static function restoreOwnModes(int $deviceId): void
    {
        event(new PlaybackModesUpdated((string) $deviceId, Modes::own($deviceId) ?? new PlaybackModes));
    }
}
