<?php

namespace App\Domain\Device;

use App\Domain\Device\Cache\Modes;
use App\Domain\Spotify\LibrespotStream;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Sonos\MusicPlayerDriver as SonosDriver;
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
 *
 * This is the Spotify rule of `DeviceRouting`, which callers ask for the driver and the capabilities (it adds the sub
 * device rule); the Spotify-only state (routed device, modes, device lists) stays here.
 */
final class SpotifyRouting
{
    /**
     * Contracts the Spotify driver handles for the routed speaker (the same as for a sub device, see DeviceRouting).
     * Volume, mute, power, sources, multiroom, radio and library playback stay with the speaker's own driver.
     */
    public const DELEGATED_CONTRACTS = DeviceRouting::DELEGATED_CONTRACTS;

    public static function routedDeviceId(): ?int
    {
        $id = DeviceCache::getSpotifyRoutedDeviceId();

        return $id === null ? null : (int) $id;
    }

    /**
     * While Spotify is paused, does the speaker play something by itself, so that routing must end? A speaker plays
     * by itself when it reports `playing`. The librespot receiver is the exception: its speaker keeps `playing` the
     * (silent) stream while Spotify is paused, so it counts only once the stream is no longer what it plays.
     */
    public static function speakerPlaysByItself(int $deviceId, ?string $spotifyDeviceName): bool
    {
        if (DeviceCache::getState($deviceId) !== State::Playing) {
            return false;
        }

        $isReceiver = LibrespotStream::enabled() && $spotifyDeviceName === (string) config('librespot.name');

        return !($isReceiver && LibrespotStream::stillStreaming($deviceId));
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

    /**
     * Sonos plays Spotify through its own integration, which Spotify reports as
     * a restricted device: the Web API refuses playback commands for it. Its
     * own driver (UPnP) controls that playback, so only contracts it lacks
     * (like) are delegated to Spotify.
     */
    public static function controlsSpotifyItself(Device $device): bool
    {
        return is_a((string) $device->device_driver, SonosDriver::class, true);
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
            if (self::controlsSpotifyItself($device) && $device->driver instanceof $contract) {
                return $device;
            }

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
     * (`DeviceRouting::capabilities()` is what the API reports: it adds the sub device rule too.)
     *
     * @return string[]
     */
    public static function capabilities(Device $device): array
    {
        return Capabilities::sort(array_merge(Capabilities::forDriver($device->device_driver), self::delegatedCapabilities($device)));
    }

    /**
     * The Spotify driver's playback capabilities while $device is the routed target, else none.
     *
     * @return string[]
     */
    public static function delegatedCapabilities(Device $device): array
    {
        if (!self::isRoutedTarget($device) || !($spotify = self::spotifyDevice())) {
            return [];
        }

        return array_values(array_intersect(
            Capabilities::forDriver($spotify->device_driver),
            Capabilities::forContracts(self::DELEGATED_CONTRACTS),
        ));
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
