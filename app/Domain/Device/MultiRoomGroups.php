<?php

namespace App\Domain\Device;

use App\Domain\Device\Cache\MultiRoom;
use App\Models\Device;
use Illuminate\Support\Collection;

class MultiRoomGroups
{
    /**
     * A device list as the dashboard shows it: a device joined to a host that is in the list is left out,
     * the host's card carries it as a member. A listener whose host isn't listed keeps its own card.
     *
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, Device>
     */
    public static function withoutListeners(Collection $devices): Collection
    {
        return $devices->reject(function (Device $device) use ($devices) {
            $status = MultiRoom::get($device->id);
            if ($status?->role !== MultiRoomStatus::LISTENER) {
                return false;
            }

            $hostId = $status->resolve($device)['host']['id'] ?? null;

            return $hostId !== null && $devices->contains('id', $hostId);
        });
    }
}
