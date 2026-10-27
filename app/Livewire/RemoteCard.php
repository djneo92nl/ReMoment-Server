<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\RemoteKey;
use App\Integrations\Contracts\RemoteControlInterface;
use App\Models\Device;
use Livewire\Component;

/**
 * The remote card of a TV's page: a D-pad with OK / Back / Home, volume and channel keys, transport and colour
 * keys. Only the keys the driver lists in `supportedKeys()` are drawn, and the card exists only with the
 * `remote_control` capability. Guest-accessible like the playback controls. Number keys stay in `TvExtras`.
 */
class RemoteCard extends Component
{
    public Device $device;

    /** @var string[] */
    public array $keys = [];

    public ?string $error = null;

    public function mount(Device $device): void
    {
        $this->device = $device;

        $driver = in_array('remote_control', DeviceCapabilities::for($device), true) ? $device->driver : null;
        $this->keys = $driver instanceof RemoteControlInterface
            ? array_map(fn (RemoteKey $key) => $key->value, $driver->supportedKeys())
            : [];
    }

    public function press(string $key): void
    {
        $case = RemoteKey::tryFrom($key);

        if ($case === null || !in_array($key, $this->keys, true)) {
            return;
        }

        try {
            $driver = $this->device->driver;
            if ($driver instanceof RemoteControlInterface) {
                $driver->sendKey($case);
            }
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.remote-card');
    }
}
