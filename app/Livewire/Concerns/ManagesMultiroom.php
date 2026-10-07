<?php

namespace App\Livewire\Concerns;

use App\Domain\Device\DeviceVolume;
use App\Domain\Device\MultiRoomPeers;
use App\Domain\Device\State;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

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
        $this->joinableSessions = $this->sameBrandPeers(playing: true);

        // If this device is playing, also load listener info
        if ($this->multiroomDevice()->state === State::Playing) {
            try {
                $this->currentListeners = $this->mapPeerIdsToDevices($driver->getCurrentPeerIds());

                // Pre-validated list from device API; empty when the device lists none or we know none of them
                $this->invitableDevices = $this->mapPeerIdsToDevices($driver->getJoinablePeerIds());

                if (empty($this->invitableDevices)) {
                    // Optimistic fallback: all same-brand non-playing devices
                    $this->invitableDevices = $this->sameBrandPeers(playing: false, except: array_column($this->currentListeners, 'id'));
                }
            } catch (\Throwable $e) {
                $this->multiroomError = 'Could not retrieve multiroom info.';
            }
        }

        $this->multiRoomDataLoaded = true;
    }

    public function joinSession(int $hostDeviceId): void
    {
        $this->multiroomAction('Join failed', function () use ($hostDeviceId) {
            $this->multiRoomDriver($this->multiroomDevice())?->joinSession(Device::findOrFail($hostDeviceId));
        });
    }

    public function inviteDevice(int $guestDeviceId): void
    {
        $this->multiroomAction('Invite failed', function () use ($guestDeviceId) {
            $guestDriver = $this->multiRoomDriver(Device::findOrFail($guestDeviceId));

            if ($guestDriver !== null) {
                $guestDriver->joinSession($this->multiroomDevice());
                // Refresh listener list
                $this->loadMultiRoomData();
            }
        });
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
        $this->multiroomAction('Remove failed', fn () => $this->multiRoomDriver(Device::findOrFail($listenerId))?->leaveSession());
    }

    public function leaveSession(): void
    {
        $this->multiroomAction('Leave failed', fn () => $this->multiRoomDriver($this->multiroomDevice())?->leaveSession());
    }

    /** The devices of every row of the picker (members, invitable, joinable), by id. */
    #[Computed]
    public function multiroomRooms(): Collection
    {
        $ids = array_merge(array_column($this->currentListeners, 'id'), array_column($this->invitableDevices, 'id'), array_column($this->joinableSessions, 'id'));

        return Device::whereIn('id', $ids)->get()->keyBy('id');
    }

    /** Runs a multiroom action; a failure is shown as "$failure: reason". */
    private function multiroomAction(string $failure, \Closure $action): void
    {
        $this->multiroomError = null;

        try {
            $action();
        } catch (\Throwable $e) {
            $this->multiroomError = "{$failure}: {$e->getMessage()}";
        }
    }

    private function multiRoomDriver(Device $device): ?MultiRoomInterface
    {
        $driver = $device->driver;

        return $driver instanceof MultiRoomInterface ? $driver : null;
    }

    /**
     * Other devices of this device's brand that can do multiroom, as `{id, device_name}`:
     * the ones playing (sessions to join) or not (devices to invite).
     *
     * @param  int[]  $except
     */
    private function sameBrandPeers(bool $playing, array $except = []): array
    {
        $device = $this->multiroomDevice();

        return Device::where('id', '!=', $device->id)
            ->where('device_brand_name', $device->device_brand_name)
            ->whereNotIn('id', $except)
            ->get()
            ->filter(function ($d) use ($playing) {
                try {
                    return ($d->state === State::Playing) === $playing && $d->driver instanceof MultiRoomInterface;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name])
            ->values()
            ->all();
    }

    private function mapPeerIdsToDevices(array $ids): array
    {
        return MultiRoomPeers::devices($ids, $this->multiroomDevice())
            ->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name])
            ->all();
    }
}
