<?php

namespace App\Livewire;

use App\Domain\Device\VolumeLimits;
use App\Models\Device;
use Livewire\Component;

/**
 * The "Volume limits" card on a device's settings page: a maximum volume and a lower one during quiet hours.
 * Admin only (it restricts what everyone else can do); every action checks the login again.
 */
class DeviceVolumeLimits extends Component
{
    public Device $device;

    public ?int $max = null;

    public ?int $quietMax = null;

    public ?string $quietFrom = null;

    public ?string $quietUntil = null;

    public ?string $notice = null;

    public function mount(Device $device): void
    {
        $this->device = $device;
        $limits = VolumeLimits::get($device);
        $this->max = $limits['max'];
        $this->quietMax = $limits['quiet_max'];
        $this->quietFrom = $limits['quiet_from'];
        $this->quietUntil = $limits['quiet_until'];
    }

    public function save(): void
    {
        abort_unless(auth()->check(), 403);

        $this->notice = null;
        foreach (['max', 'quietMax', 'quietFrom', 'quietUntil'] as $field) {
            $this->{$field} = $this->{$field} === '' ? null : $this->{$field};
        }

        $this->validate([
            'max' => ['nullable', 'integer', 'min:0', 'max:100'],
            'quietMax' => ['nullable', 'integer', 'min:0', 'max:100'],
            'quietFrom' => ['nullable', 'date_format:H:i', 'required_with:quietMax'],
            'quietUntil' => ['nullable', 'date_format:H:i', 'required_with:quietMax'],
        ], attributes: ['max' => 'maximum', 'quietMax' => 'quiet-hours maximum', 'quietFrom' => 'start', 'quietUntil' => 'end']);

        VolumeLimits::set($this->device, [
            'max' => $this->max,
            'quiet_max' => $this->quietMax,
            'quiet_from' => $this->quietFrom,
            'quiet_until' => $this->quietUntil,
        ]);

        $this->notice = 'Saved.';
    }

    public function render()
    {
        return view('livewire.device-volume-limits', ['limit' => VolumeLimits::capFor($this->device)]);
    }
}
