<?php

namespace App\Domain\Device;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * A device's multiroom peer id (a B&O JID): asked from the device once, cached for a week, and kept in
 * `device_meta` under the platform's key so other devices can find it (MultiRoomPeers).
 */
final class MultiRoomId
{
    private const TTL_SECONDS = 86400 * 7;

    /** @param  \Closure(): ?string  $find  asks the device for its id */
    public static function remember(Device $device, string $metaKey, \Closure $find): ?string
    {
        $cacheKey = "device:{$device->id}:{$metaKey}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $id = $find();

        if ($id) {
            Cache::put($cacheKey, $id, self::TTL_SECONDS);
            $device->meta()->updateOrCreate(['key' => $metaKey], ['value' => $id]);
        }

        return $id;
    }
}
