<?php

namespace App\Domain\Device\Cache;

use App\Domain\Device\MultiRoomStatus;
use Illuminate\Support\Facades\Cache;

/** Last known multiroom role of a device, as reported by its listener. Absent while unknown. */
final class MultiRoom
{
    private const TTL = 3600;

    public static function get(int $deviceId): ?MultiRoomStatus
    {
        $value = Cache::get(self::key($deviceId));

        return is_array($value) ? MultiRoomStatus::fromArray($value) : null;
    }

    public static function put(int $deviceId, MultiRoomStatus $status): void
    {
        Cache::put(self::key($deviceId), $status->toArray(), self::TTL);
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::key($deviceId));
    }

    private static function key(int $deviceId): string
    {
        return "device:{$deviceId}:multiroom";
    }
}
