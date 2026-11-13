<?php

namespace App\Livewire;

use App\Domain\Party\Party;
use App\Models\Device;
use App\Support\QrCode;
use Livewire\Component;

/** The "Party" card on a device's settings page: switch the guest queue on, set the limit, show the link and QR code. Admin only. */
class DevicePartySettings extends Component
{
    public Device $device;

    public int $limit = 3;

    public int $windowMinutes = 10;

    public function mount(Device $device): void
    {
        $this->device = $device;
        $party = Party::for($device);
        $this->limit = $party['limit'];
        $this->windowMinutes = $party['window_minutes'];
    }

    public function toggle(): void
    {
        abort_unless(auth()->check(), 403);

        Party::for($this->device)['enabled'] ? Party::disable($this->device) : $this->save();
    }

    public function save(): void
    {
        abort_unless(auth()->check(), 403);

        $this->validate(['limit' => ['required', 'integer', 'min:1', 'max:50'], 'windowMinutes' => ['required', 'integer', 'min:1', 'max:240']]);
        Party::enable($this->device, $this->limit, $this->windowMinutes);
    }

    public function newLink(): void
    {
        abort_unless(auth()->check(), 403);

        Party::rotate($this->device);
    }

    public function render()
    {
        $party = Party::for($this->device);
        $url = Party::url($party);

        return view('livewire.device-party-settings', ['party' => $party, 'url' => $url, 'qr' => $url ? QrCode::svg($url) : null]);
    }
}
