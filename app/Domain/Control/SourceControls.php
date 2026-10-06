<?php

namespace App\Domain\Control;

use App\Domain\Artwork\SourceLogo;
use App\Domain\Device\DeviceCache;
use App\Domain\Media\Source;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use Illuminate\Support\Arr;

/**
 * The controls of the source a device is playing right now. The profile comes
 * from the active source (SourceLogo's word matching), so a converter's own CD,
 * the same CD borrowed by a player and a CD changer on an AUX input all show
 * the same controls; the transport decides how a command reaches the device
 * the source is playing on.
 */
final class SourceControls
{
    /** The profile for what the device is playing, or null. */
    public static function profileFor(Device $device): ?ControlProfile
    {
        $source = DeviceCache::getNowPlaying($device->id)?->source;

        return $source ? self::profileForSource($source) : null;
    }

    public static function profileForSource(Source $source): ?ControlProfile
    {
        $profiles = config('control-profiles.profiles', []);
        $rules = array_map(fn (array $p) => $p['match'], $profiles);

        $key = SourceLogo::firstMatch(
            [$source->sourceType, $source->name, $source->connector, $source->category],
            $rules,
        );

        if ($key === null) {
            return null;
        }

        return new ControlProfile($key, $profiles[$key]['label'], $profiles[$key]['commands']);
    }

    /** The profile, when a transport can also deliver its commands to this device. */
    public static function available(Device $device): ?ControlProfile
    {
        $profile = self::profileFor($device);

        return $profile && self::transportFor($device, $profile) ? $profile : null;
    }

    public static function run(Device $device, string $commandId): void
    {
        $profile = self::available($device);

        if (!$profile || !$profile->has($commandId)) {
            throw new UnsupportedOperationException("No source control '{$commandId}' for this device.");
        }

        self::transportFor($device, $profile)->send($device, $profile, $commandId);
    }

    private static function transportFor(Device $device, ControlProfile $profile): ?ControlTransport
    {
        foreach (Arr::wrap(config('control-profiles.transports', [])) as $class) {
            $transport = app($class);

            if ($transport->supports($device, $profile)) {
                return $transport;
            }
        }

        return null;
    }
}
