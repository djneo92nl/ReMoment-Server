<?php

namespace App\Domain\Device;

use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;

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

        return Device::whereHas('meta', function ($q) use ($ids, $keys) {
            $q->whereIn('key', $keys)->whereIn('value', $ids);
        })->where('id', '!=', $exclude->id)->get();
    }
}
