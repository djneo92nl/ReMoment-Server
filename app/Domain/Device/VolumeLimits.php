<?php

namespace App\Domain\Device;

use App\Domain\Device\Cache\Volume;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Volume guardrails of a device: a maximum volume, and a lower one during quiet hours. Kept as JSON in the
 * device meta key `volume_limits`. Enforced where the server sets a volume (DeviceVolume, the API) and where a
 * device reports one (EnforceVolumeLimit), so a turn of the wheel on the speaker is pulled back as well.
 */
final class VolumeLimits
{
    public const META_KEY = 'volume_limits';

    /** Seconds a correction is given to arrive before the next one is sent. */
    private const CORRECTION_PAUSE = 3;

    private const EMPTY = ['max' => null, 'quiet_max' => null, 'quiet_from' => null, 'quiet_until' => null];

    /** @return array{max: ?int, quiet_max: ?int, quiet_from: ?string, quiet_until: ?string} */
    public static function get(Device $device): array
    {
        $stored = Cache::remember(self::cacheKey($device->id), 3600, function () use ($device) {
            $value = DeviceMeta::where('device_id', $device->id)->where('key', self::META_KEY)->value('value');

            return is_string($value) ? (json_decode($value, true) ?: []) : [];
        });

        return array_merge(self::EMPTY, array_intersect_key($stored, self::EMPTY));
    }

    /**
     * @param  array{max?: ?int, quiet_max?: ?int, quiet_from?: ?string, quiet_until?: ?string}  $limits
     * @return array{max: ?int, quiet_max: ?int, quiet_from: ?string, quiet_until: ?string}
     */
    public static function set(Device $device, array $limits): array
    {
        $limits = array_merge(self::get($device), array_intersect_key($limits, self::EMPTY));

        foreach (['max', 'quiet_max'] as $key) {
            $limits[$key] = $limits[$key] === null || $limits[$key] === '' ? null : max(0, min(100, (int) $limits[$key]));
        }
        foreach (['quiet_from', 'quiet_until'] as $key) {
            $limits[$key] = self::validTime($limits[$key]) ? $limits[$key] : null;
        }

        if (array_filter($limits, fn ($value) => $value !== null) === []) {
            DeviceMeta::where('device_id', $device->id)->where('key', self::META_KEY)->delete();
        } else {
            DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => self::META_KEY], ['value' => json_encode($limits)]);
        }
        Cache::forget(self::cacheKey($device->id));

        return $limits;
    }

    /** The highest volume allowed now; null when the device has no limit. */
    public static function capFor(Device $device, ?CarbonInterface $now = null): ?int
    {
        $limits = self::get($device);
        $caps = [];

        if ($limits['max'] !== null) {
            $caps[] = $limits['max'];
        }
        if ($limits['quiet_max'] !== null && self::inQuietHours($limits, $now ?? now())) {
            $caps[] = $limits['quiet_max'];
        }

        return $caps === [] ? null : min($caps);
    }

    public static function clamp(Device $device, int $volume): int
    {
        $cap = self::capFor($device);

        return $cap === null ? $volume : min($volume, $cap);
    }

    /**
     * Lower the device to its limit when `$volume` (what it reports or what the cache has) is above it.
     * At most one correction per few seconds, so a device that is slow to apply it is not flooded.
     *
     * @return bool whether a correction was sent
     */
    public static function enforce(Device $device, int $volume): bool
    {
        $cap = self::capFor($device);
        if ($cap === null || $volume <= $cap || !Cache::add("device:{$device->id}:volume_limit_sent", true, self::CORRECTION_PAUSE)) {
            return false;
        }

        try {
            $driver = $device->driver;
            if (!($driver instanceof VolumeControlInterface)) {
                return false;
            }
            $driver->setVolume($cap);
            Volume::updateVolume($device->id, $cap);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Volume limit of device {$device->id} not applied: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Every minute: devices that are louder than their limit now, e.g. because quiet hours just began.
     * Reads the cached volume only, so an idle device costs nothing.
     *
     * @return int the number of devices lowered
     */
    public static function enforceDue(): int
    {
        $lowered = 0;
        $ids = DeviceMeta::where('key', self::META_KEY)->pluck('device_id');

        foreach (Device::whereIn('id', $ids)->get() as $device) {
            $volume = Volume::getVolume($device->id);
            if ($volume !== false && self::enforce($device, $volume)) {
                $lowered++;
            }
        }

        return $lowered;
    }

    /** @param array{quiet_from: ?string, quiet_until: ?string} $limits */
    private static function inQuietHours(array $limits, CarbonInterface $now): bool
    {
        if (!self::validTime($limits['quiet_from']) || !self::validTime($limits['quiet_until'])) {
            return false;
        }

        $time = $now->format('H:i');
        [$from, $until] = [$limits['quiet_from'], $limits['quiet_until']];

        // 22:00 to 07:00 wraps past midnight.
        return $from <= $until ? ($time >= $from && $time < $until) : ($time >= $from || $time < $until);
    }

    private static function validTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function cacheKey(int $deviceId): string
    {
        return "device:{$deviceId}:volume_limits";
    }
}
