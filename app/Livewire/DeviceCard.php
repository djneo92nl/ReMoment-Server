<?php

namespace App\Livewire;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\Play;
use App\Models\RadioStation;
use Livewire\Component;

class DeviceCard extends Component
{
    public Device $device;

    public int $volume = 0;

    public bool $listenerRunning = false;

    public bool $standalone = false; // disables md:col-span-2 (use on show page)

    public bool $supportsMultiRoom = false;

    public bool $supportsSourceActivation = false;

    public bool $supportsRadio = false;

    public array $quickSources = [];

    public ?array $lastRadioStation = null;

    // Multiroom modal state
    public bool $multiRoomDataLoaded = false;

    public array $joinableSessions = [];

    public array $currentListeners = [];

    public array $invitableDevices = [];

    public ?string $multiroomError = null;

    public function mount(Device $device): void
    {
        $this->device = $device;
        $this->refresh();
        try {
            $driver = $device->driver;
            $this->supportsMultiRoom = $driver instanceof MultiRoomInterface;
            $this->supportsSourceActivation = $driver instanceof SourceActivationInterface;
            $this->supportsRadio = $driver instanceof RadioControlInterface;
        } catch (\Throwable) {
            $this->supportsMultiRoom = false;
            $this->supportsSourceActivation = false;
            $this->supportsRadio = false;
        }

        if ($this->supportsSourceActivation) {
            $streamingTypes = ['spotify', 'deezer', 'tidal', 'qobuz'];
            $this->quickSources = $device->deviceSources()
                ->where('borrowed', false)
                ->where('hidden', false)
                ->whereNotIn('source_type', $streamingTypes)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->source_id,
                    'name' => $s->friendly_name,
                    'type' => $s->source_type,
                    'category' => $s->category,
                ])
                ->values()
                ->all();
        }

        if ($this->supportsRadio) {
            $lastPlay = Play::where('device_id', $device->id)
                ->where('source_type', 'radio')
                ->whereNotNull('radio_station_id')
                ->with('radioStation')
                ->latest('played_at')
                ->first();

            if ($lastPlay?->radioStation) {
                $this->lastRadioStation = [
                    'id' => $lastPlay->radioStation->id,
                    'name' => $lastPlay->radioStation->name,
                ];
            }
        }
    }

    public function render()
    {
        $this->refresh();

        return view('livewire.device-card');
    }

    public function play(): void
    {
        $this->withDriver(fn ($d) => $d->play());
    }

    public function pause(): void
    {
        $this->withDriver(fn ($d) => $d->pause());
    }

    public function next(): void
    {
        $this->withDriver(fn ($d) => $d->next());
    }

    public function previous(): void
    {
        $this->withDriver(fn ($d) => $d->previous());
    }

    public function standby(): void
    {
        $this->withDriver(fn ($d) => $d->standby());
    }

    public function playLastRadioStation(): void
    {
        if (!$this->lastRadioStation) {
            return;
        }
        try {
            $driver = $this->device->driver;
            if ($driver instanceof RadioControlInterface) {
                $station = RadioStation::find($this->lastRadioStation['id']);
                if ($station) {
                    $driver->playRadioStation($station);
                }
            }
        } catch (\Throwable) {
        }
    }

    public function activateSource(string $sourceId): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof SourceActivationInterface) {
                $driver->activateSource($sourceId);
            }
        } catch (\Throwable) {
        }
    }

    public function setVolume(int $volume): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $driver->setVolume($volume);
                $this->volume = $volume;
            }
        } catch (\Throwable) {
        }
    }

    public function loadMultiRoomData(): void
    {
        $this->multiroomError = null;
        $driver = $this->device->driver;

        if (!($driver instanceof MultiRoomInterface)) {
            return;
        }

        // Playing sessions this device can join (other same-brand Playing devices)
        $this->joinableSessions = Device::where('id', '!=', $this->device->id)
            ->where('device_brand_name', $this->device->device_brand_name)
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
        if ($this->device->state === State::Playing) {
            try {
                $listenerIds = $driver->getCurrentPeerIds();
                $this->currentListeners = $this->mapPeerIdsToDevices($listenerIds);

                $joinableIds = $driver->getJoinablePeerIds();

                if (!empty($joinableIds)) {
                    // Pre-validated list from device API
                    $this->invitableDevices = $this->mapPeerIdsToDevices($joinableIds);
                } else {
                    // Optimistic fallback: all same-brand non-playing devices
                    $currentListenerDeviceIds = array_column($this->currentListeners, 'id');
                    $this->invitableDevices = Device::where('id', '!=', $this->device->id)
                        ->where('device_brand_name', $this->device->device_brand_name)
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
            $driver = $this->device->driver;
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
            $guestDriver->joinSession($this->device);
            // Refresh listener list
            $this->loadMultiRoomData();
        } catch (\Throwable $e) {
            $this->multiroomError = 'Invite failed: '.$e->getMessage();
        }
    }

    public function leaveSession(): void
    {
        $this->multiroomError = null;
        try {
            $driver = $this->device->driver;
            if ($driver instanceof MultiRoomInterface) {
                $driver->leaveSession();
            }
        } catch (\Throwable $e) {
            $this->multiroomError = 'Leave failed: '.$e->getMessage();
        }
    }

    private function mapPeerIdsToDevices(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $driver = $this->device->driver;
        if (!($driver instanceof MultiRoomInterface)) {
            return [];
        }

        $metaKey = $driver->multiRoomMetaKey();

        return Device::whereHas('meta', function ($q) use ($ids, $metaKey) {
            $q->where('key', $metaKey)->whereIn('value', $ids);
        })->where('id', '!=', $this->device->id)
            ->get()
            ->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name])
            ->all();
    }

    private function refresh(): void
    {
        $this->listenerRunning = DeviceCache::isListenerRunning($this->device->id);
        $this->volume = (int) (Volume::getVolume($this->device->id) ?: 0);
    }

    private function withDriver(callable $callback): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof MediaControlsInterface) {
                $callback($driver);
            }
        } catch (\Throwable) {
            // silently ignore driver errors in card context
        }
    }
}
