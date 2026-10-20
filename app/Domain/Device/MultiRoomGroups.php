<?php

namespace App\Domain\Device;

use App\Domain\Device\Cache\MultiRoom;
use App\Models\Device;
use Illuminate\Support\Collection;

class MultiRoomGroups
{
    /**
     * A device list as the dashboard shows it: a device joined to a host that is in the list is left out,
     * the host's card carries it as a member. A listener whose host isn't listed, or whose host's card doesn't
     * list it, keeps its own card.
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
            $host = $hostId === null ? null : $devices->firstWhere('id', $hostId);
            if ($host === null) {
                return false;
            }

            // Only fold it away when the host's card will actually list it as a member; a host without a
            // (fresh) status of its own would otherwise make the listener vanish from the dashboard.
            $hostStatus = MultiRoom::get($host->id);
            if ($hostStatus?->role !== MultiRoomStatus::HOST) {
                return false;
            }

            return collect($hostStatus->resolve($host)['listeners'])->contains('id', $device->id);
        });
    }
}
