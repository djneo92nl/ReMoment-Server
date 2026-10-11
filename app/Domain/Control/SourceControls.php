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

        $key ??= self::keyByType($profiles, $source->sourceType);

        if ($key === null) {
            return null;
        }

        return new ControlProfile($key, $profiles[$key]['label'], $profiles[$key]['commands']);
    }

    /**
     * The profile, when a transport can also deliver its commands to this device,
     * limited to the commands that transport can send.
     */
    public static function available(Device $device): ?ControlProfile
    {
        $profile = self::profileFor($device);
        $transport = $profile ? self::transportFor($device, $profile) : null;

        if (!$transport) {
            return null;
        }

        $ids = $transport->commands($device, $profile);

        return $ids === null ? $profile : $profile->only($ids);
    }

    public static function run(Device $device, string $commandId): void
    {
        $profile = self::available($device);

        if (!$profile || !$profile->has($commandId)) {
            throw new UnsupportedOperationException("No source control '{$commandId}' for this device.");
        }

        self::transportFor($device, $profile)->send($device, $profile, $commandId);
    }

    /**
     * A profile whose `source_types` name the source's type exactly (case
     * insensitive): for sources whose words are too common to match on, like
     * a converter's "RADIO" (a net radio's type is "NET RADIO", not "RADIO").
     */
    private static function keyByType(array $profiles, ?string $sourceType): ?string
    {
        if ($sourceType === null || $sourceType === '') {
            return null;
        }

        foreach ($profiles as $key => $profile) {
            foreach ($profile['source_types'] ?? [] as $type) {
                if (strcasecmp($type, $sourceType) === 0) {
                    return $key;
                }
            }
        }

        return null;
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
