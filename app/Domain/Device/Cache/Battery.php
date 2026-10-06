<?php

namespace App\Domain\Device\Cache;

use App\Domain\Device\BatteryStatus;
use Illuminate\Support\Facades\Cache;

/** Last known battery status per device, as reported by its listener. Absent for devices without a battery. */
final class Battery
{
    // Listeners re-report on a timer or on change; a device that stops reporting drops out after this.
    private const TTL = 3600;

    public static function get(int $deviceId): ?BatteryStatus
    {
        $value = Cache::get(self::key($deviceId));

        return is_array($value) ? BatteryStatus::fromArray($value) : null;
    }

    public static function put(int $deviceId, BatteryStatus $status): void
    {
        Cache::put(self::key($deviceId), $status->toArray(), self::TTL);
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::key($deviceId));
    }

    private static function key(int $deviceId): string
    {
        return "device:{$deviceId}:battery";
    }
}
