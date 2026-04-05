<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\Play;
use Livewire\Component;

class DeviceHistory extends Component
{
    public Device $device;

    public function render()
    {
        $plays = Play::query()
            ->where('device_id', $this->device->id)
            ->whereNotNull('track_id')
            ->with(['track.artist', 'track.album'])
            ->latest('played_at')
            ->limit(20)
            ->get()
            ->unique('track_id')
            ->take(10);

        return view('livewire.device-history', ['plays' => $plays]);
    }
}
