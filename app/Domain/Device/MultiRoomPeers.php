<?php

namespace App\Domain\Device;

use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class MultiRoomPeers
{
    /**
     * Devices behind the given multiroom peer IDs, whatever platform they are on.
     *
     * @param  array<int, string>  $ids
     * @return Collection<int, Device>
     */
    public static function devices(array $ids, Device $exclude): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        $driver = $exclude->driver;
        if (!($driver instanceof MultiRoomInterface)) {
            return new Collection;
        }

        $keys = array_values(array_unique([
            $driver->multiRoomMetaKey(),
            ...config('devices.multiroom_meta_keys', []),
        ]));

        $find = fn () => Device::whereHas('meta', function ($q) use ($ids, $keys) {
            $q->whereIn('key', $keys)->whereIn('value', $ids);
        })->where('id', '!=', $exclude->id)->get();

        $found = $find();

        // A peer ID nobody owns yet usually means that device's ID was never stored
        // (device:sync-sources not run): ask the multiroom devices without one, then look again.
        if ($found->count() < count($ids) && self::storeMissingIds($keys, $exclude)) {
            $found = $find();
        }

        return $found;
    }

    /** Reads and stores the peer ID of every other multiroom device that has none. */
    private static function storeMissingIds(array $keys, Device $exclude): bool
    {
        $stored = false;

        $candidates = Device::where('id', '!=', $exclude->id)
            ->whereDoesntHave('meta', fn ($q) => $q->whereIn('key', $keys))
            ->get();

        foreach ($candidates as $candidate) {
            // Unreachable devices only cost a timeout, and a device that can't tell is not asked again at once.
            if ($candidate->state === State::Unreachable || !Cache::add("multiroom:jid-lookup:{$candidate->id}", true, 600)) {
                continue;
            }

            try {
                $driver = $candidate->driver;
                if ($driver instanceof MultiRoomInterface && $driver->getMultiRoomId()) {
                    $stored = true;
                }
            } catch (\Throwable) {
                // Unreachable or not a multiroom device: nothing to store.
            }
        }

        return $stored;
    }
}
