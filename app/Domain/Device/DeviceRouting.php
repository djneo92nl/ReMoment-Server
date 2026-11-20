<?php

namespace App\Domain\Device;

use App\Domain\Device\Routing\RoutingRule;
use App\Domain\Device\Routing\SpotifyRoute;
use App\Domain\Device\Routing\SubDeviceRoute;
use App\Domain\Media\NowPlaying;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueClearInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\QueueMoveInterface;
use App\Integrations\Contracts\QueuePlayNextInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Models\Device;

/**
 * One device made of two, generalised from SpotifyRouting (workstream 2a, docs/architecture/sub-devices.md): which
 * device's driver executes a playback command sent to $device, which capabilities $device reports, and whose now playing
 * it shows. The rules are Spotify Connect and an active sub device link; a sub device is asked first, because an input
 * the host has selected is something a person did, Spotify routing is a speaker following a cloud session. Everything that
 * sends a playback command (API, web, MCP, scenes, sync groups, announcements) asks here, never the device's own driver
 * directly. Volume, mute, power, sources, multiroom, radio and library playback stay with the host's own driver.
 */
final class DeviceRouting
{
    /** Contracts that move to the routed device: transport, seek, queue and the playback modes. */
    public const DELEGATED_CONTRACTS = [
        MediaControlsInterface::class,
        SeekInterface::class,
        QueueInterface::class,
        QueueJumpInterface::class,
        QueueAddInterface::class,
        QueuePlayNextInterface::class,
        QueueRemoveInterface::class,
        QueueMoveInterface::class,
        QueueClearInterface::class,
        ShuffleInterface::class,
        RepeatInterface::class,
        LikeInterface::class,
    ];

    /** @return RoutingRule[] in the order they are asked */
    public static function rules(): array
    {
        return [new SubDeviceRoute, new SpotifyRoute];
    }

    /** The device whose driver executes $contract for $device: the routed one, else $device itself. */
    public static function deviceFor(Device $device, string $contract): Device
    {
        foreach (self::rules() as $rule) {
            if (($target = $rule->target($device, $contract)) !== null) {
                return $target;
            }
        }

        return $device;
    }

    public static function driverFor(Device $device, string $contract): object
    {
        return self::deviceFor($device, $contract)->driver;
    }

    /**
     * The device a shuffle/repeat/like change made through $device is reported for: the sub device that executes it
     * (it reports its own modes), else, as before, the speaker Spotify is routed to or $device itself.
     */
    public static function modesDeviceId(Device $device, string $contract): int
    {
        $target = self::deviceFor($device, $contract);

        if ($target->id !== $device->id && !SpotifyRouting::isSpotify($target)) {
            return $target->id;
        }

        return SpotifyRouting::modesDeviceId($device);
    }

    /**
     * API capabilities of $device: its own driver's, plus the playback capabilities of what it is routed to.
     *
     * @return string[]
     */
    public static function capabilities(Device $device): array
    {
        $capabilities = Capabilities::forDriver($device->device_driver);

        foreach (self::rules() as $rule) {
            $capabilities = [...$capabilities, ...$rule->capabilities($device)];
        }

        return Capabilities::sort($capabilities);
    }

    /**
     * What $device shows as now playing: the active sub device's, while that one has something playing or paused
     * (a cast session behind a TV input), else its own.
     */
    public static function nowPlayingFor(Device $device): ?NowPlaying
    {
        return DeviceCache::getNowPlaying(self::nowPlayingDevice($device)->id);
    }

    /** The device whose now playing (and playback modes) $device shows: the active sub device that plays something, else $device. */
    public static function nowPlayingDevice(Device $device): Device
    {
        $sub = (new SubDeviceRoute)->active($device);

        if ($sub !== null && in_array(DeviceCache::getState($sub->id), [State::Playing, State::Paused], true)) {
            $subNow = DeviceCache::getNowPlaying($sub->id);

            if ($subNow?->track !== null || $subNow?->radio !== null) {
                return $sub;
            }
        }

        return $device;
    }
}
