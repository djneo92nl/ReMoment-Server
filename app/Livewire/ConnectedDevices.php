<?php

namespace App\Livewire;

use App\Domain\SubDevices\DeviceLinks;
use App\Domain\SubDevices\InvalidDeviceLink;
use App\Domain\SubDevices\LinkSuggestions;
use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Component;

/**
 * The "Connected devices" card on the device edit page: the sub devices connected to this device (it is their host)
 * and the hosts it is connected to, with add and remove by hand, and the links the device reports itself (an ASE
 * player's Products list). Admin only, like every configuration action; the card is not shown to a guest.
 */
class ConnectedDevices extends Component
{
    public Device $device;

    /** `sub`: the chosen device is connected to this one (this is the host); `host`: this device is connected to the chosen one. */
    public string $direction = 'sub';

    public ?int $otherId = null;

    public string $kind = DeviceLink::KIND_INPUT;

    public string $via = '';

    public ?string $error = null;

    public ?string $notice = null;

    /** @var array<int, array<string, mixed>> */
    public array $suggestions = [];

    public function mount(Device $device): void
    {
        $this->device = $device;
    }

    public function add(): void
    {
        $this->guard();
        $this->validate([
            'direction' => ['required', 'in:sub,host'],
            'otherId' => ['required', 'integer', 'exists:devices,id'],
            'kind' => ['required', 'in:'.implode(',', DeviceLink::KINDS)],
            'via' => ['nullable', 'string', 'max:191'],
        ]);

        $other = Device::findOrFail($this->otherId);

        $this->save($this->direction === 'sub' ? $other : $this->device, $this->direction === 'sub' ? $this->device : $other, $this->kind, $this->via);

        if ($this->error === null) {
            $this->reset('otherId', 'via');
        }
    }

    public function remove(int $linkId): void
    {
        $this->guard();

        DeviceLink::where('id', $linkId)
            ->where(fn ($query) => $query->where('device_id', $this->device->id)->orWhere('host_device_id', $this->device->id))
            ->delete();

        $this->error = null;
        $this->notice = 'The link is removed. Both devices keep working on their own.';
    }

    /** Asks this device for the products it knows (a network call, so only on this button). */
    public function findSuggestions(): void
    {
        $this->guard();
        $this->error = $this->notice = null;

        try {
            $driver = $this->device->driver;
            $this->suggestions = method_exists($driver, 'getProducts') ? LinkSuggestions::fromProducts($this->device, $driver->getProducts()) : [];
        } catch (\Throwable $e) {
            $this->suggestions = [];
            $this->error = 'The device did not answer: '.$e->getMessage();

            return;
        }

        $this->notice = $this->suggestions === [] ? 'The device reports no product that is one of your devices.' : null;
    }

    public function accept(int $index): void
    {
        $this->guard();
        $suggestion = $this->suggestions[$index] ?? null;

        if ($suggestion === null || ($sub = Device::find($suggestion['device_id'])) === null) {
            return;
        }

        $this->save($sub, $this->device, $suggestion['kind'], $suggestion['via']);
        unset($this->suggestions[$index]);
        $this->suggestions = array_values($this->suggestions);
    }

    public function render()
    {
        $subs = DeviceLinks::subLinks($this->device);
        $hosts = DeviceLinks::hostLinks($this->device);

        return view('livewire.connected-devices', [
            'subLinks' => $subs->map(fn (DeviceLink $link) => ['link' => $link, 'active' => \App\Domain\SubDevices\LinkState::active($link->setRelation('host', $this->device))]),
            'hostLinks' => $hosts->map(fn (DeviceLink $link) => ['link' => $link, 'active' => \App\Domain\SubDevices\LinkState::active($link->setRelation('device', $this->device))]),
            'others' => Device::where('id', '!=', $this->device->id)->orderBy('device_name')->get(['id', 'device_name']),
            'canSuggest' => method_exists($this->device->driver, 'getProducts'),
        ]);
    }

    private function save(Device $sub, Device $host, string $kind, string $via): void
    {
        $this->error = $this->notice = null;

        try {
            DeviceLinks::add($sub, $host, $kind, $via);
            $this->notice = "{$sub->device_name} is connected to {$host->device_name}.";
        } catch (InvalidDeviceLink $e) {
            $this->error = $e->getMessage();
        } catch (UniqueConstraintViolationException) {
            $this->error = 'These devices are already linked that way.';
        }
    }

    private function guard(): void
    {
        abort_unless(auth()->check(), 403);
    }
}
