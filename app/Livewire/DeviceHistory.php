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
        $latestIds = Play::query()
            ->selectRaw('MAX(id) as id')
            ->where('device_id', $this->device->id)
            ->whereNotNull('track_id')
            ->groupBy('track_id')
            ->orderByRaw('MAX(played_at) DESC')
            ->limit(10)
            ->pluck('id');

        $plays = Play::query()
            ->whereIn('id', $latestIds)
            ->with(['track.artist', 'track.album'])
            ->orderByDesc('played_at')
            ->get();

        return view('livewire.device-history', ['plays' => $plays]);
    }
}
