<?php

namespace App\Livewire\Concerns;

use App\Domain\Device\DeviceVolume;
use App\Domain\Device\MultiRoomPeers;
use App\Domain\Device\State;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;

/**
 * The multiroom picker's state and actions (view: livewire.partials.multiroom-modal), for a Livewire
 * component that has one device it is about: the device card, the sticky player bar.
 */
trait ManagesMultiroom
{
    // Multiroom modal state
    public bool $multiRoomDataLoaded = false;

    public array $joinableSessions = [];

    public array $currentListeners = [];

    public array $invitableDevices = [];

    public ?string $multiroomError = null;

    /** The device the multiroom picker is for. */
    abstract protected function multiroomDevice(): Device;

    public function loadMultiRoomData(): void
    {
        $this->multiroomError = null;
        $driver = $this->multiroomDevice()->driver;

        if (!($driver instanceof MultiRoomInterface)) {
            return;
        }

        // Playing sessions this device can join (other same-brand Playing devices)
        $this->joinableSessions = Device::where('id', '!=', $this->multiroomDevice()->id)
            ->where('device_brand_name', $this->multiroomDevice()->device_brand_name)
            ->get()
            ->filter(function ($d) {
                try {
                    return $d->state === State::Playing && $d->driver instanceof MultiRoomInterface;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name])
            ->values()
            ->all();

        // If this device is playing, also load listener info
        if ($this->multiroomDevice()->state === State::Playing) {
            try {
                $listenerIds = $driver->getCurrentPeerIds();
                $this->currentListeners = $this->mapPeerIdsToDevices($listenerIds);

                // Pre-validated list from device API; empty when the device lists none or we know none of them
                $this->invitableDevices = $this->mapPeerIdsToDevices($driver->getJoinablePeerIds());

                if (empty($this->invitableDevices)) {
                    // Optimistic fallback: all same-brand non-playing devices
                    $currentListenerDeviceIds = array_column($this->currentListeners, 'id');
                    $this->invitableDevices = Device::where('id', '!=', $this->multiroomDevice()->id)
                        ->where('device_brand_name', $this->multiroomDevice()->device_brand_name)
                        ->whereNotIn('id', $currentListenerDeviceIds)
                        ->get()
                        ->filter(function ($d) {
                            try {
                                return $d->state !== State::Playing && $d->driver instanceof MultiRoomInterface;
                            } catch (\Throwable) {
                                return false;
                            }
                        })
                        ->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name])
                        ->values()
                        ->all();
                }
            } catch (\Throwable $e) {
                $this->multiroomError = 'Could not retrieve multiroom info.';
            }
        }

        $this->multiRoomDataLoaded = true;
    }

    public function joinSession(int $hostDeviceId): void
    {
        $this->multiroomError = null;
        try {
            $driver = $this->multiroomDevice()->driver;
            if (!($driver instanceof MultiRoomInterface)) {
                return;
            }
            $hostDevice = Device::findOrFail($hostDeviceId);
            $driver->joinSession($hostDevice);
        } catch (\Throwable $e) {
            $this->multiroomError = 'Join failed: '.$e->getMessage();
        }
    }

    public function inviteDevice(int $guestDeviceId): void
    {
        $this->multiroomError = null;
        try {
            $guestDevice = Device::findOrFail($guestDeviceId);
            $guestDriver = $guestDevice->driver;
            if (!($guestDriver instanceof MultiRoomInterface)) {
                return;
            }
            $guestDriver->joinSession($this->multiroomDevice());
            // Refresh listener list
            $this->loadMultiRoomData();
        } catch (\Throwable $e) {
            $this->multiroomError = 'Invite failed: '.$e->getMessage();
        }
    }

    /** Volume of one room of this session (this device's own, or a joined room's). */
    public function setMemberVolume(int $deviceId, int $volume): void
    {
        try {
            $level = DeviceVolume::set(Device::findOrFail($deviceId), $volume);

            if ($level !== null && property_exists($this, 'volume') && $deviceId === $this->multiroomDevice()->id) {
                $this->volume = $level;
            }
        } catch (\Throwable) {
        }
    }

    /** Removes a device from the group this device hosts: the device leaves by itself. */
    public function removeListener(int $listenerId): void
    {
        $this->multiroomError = null;
        try {
            $listener = Device::findOrFail($listenerId);
            $driver = $listener->driver;
            if ($driver instanceof MultiRoomInterface) {
                $driver->leaveSession();
            }
        } catch (\Throwable $e) {
            $this->multiroomError = 'Remove failed: '.$e->getMessage();
        }
    }

    public function leaveSession(): void
    {
        $this->multiroomError = null;
        try {
            $driver = $this->multiroomDevice()->driver;
            if ($driver instanceof MultiRoomInterface) {
                $driver->leaveSession();
            }
        } catch (\Throwable $e) {
            $this->multiroomError = 'Leave failed: '.$e->getMessage();
        }
    }

    private function mapPeerIdsToDevices(array $ids): array
    {
        return MultiRoomPeers::devices($ids, $this->multiroomDevice())
            ->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name])
            ->all();
    }
}
