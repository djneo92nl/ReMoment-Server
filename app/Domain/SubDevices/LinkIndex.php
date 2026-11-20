<?php

namespace App\Domain\SubDevices;

use App\Models\DeviceLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Which devices are the host of a link and which a sub device. `DeviceCapabilities::for()` is asked for every device
 * on a page (and for every play button) and every device event asks whether its device is part of a link, so the answer
 * comes from the cache, not a query. Dropped whenever a link or a device is saved or deleted; shared through the cache
 * store, so the long-running listener processes see it too.
 */
final class LinkIndex
{
    private const KEY = 'device_links:index';

    public static function isHost(int $deviceId): bool
    {
        return isset(self::read()['hosts'][$deviceId]);
    }

    public static function isSub(int $deviceId): bool
    {
        return isset(self::read()['subs'][$deviceId]);
    }

    /** @return array{hosts: array<int, true>, subs: array<int, true>} */
    private static function read(): array
    {
        try {
            return Cache::rememberForever(self::KEY, function () {
                $links = DeviceLink::query()->get(['device_id', 'host_device_id']);

                return [
                    'hosts' => $links->mapWithKeys(fn ($link) => [(int) $link->host_device_id => true])->all(),
                    'subs' => $links->mapWithKeys(fn ($link) => [(int) $link->device_id => true])->all(),
                ];
            });
        } catch (QueryException) {
            // Before `migrate` has made the table: no links.
            return ['hosts' => [], 'subs' => []];
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
