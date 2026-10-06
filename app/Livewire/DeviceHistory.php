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
        // The last 10 distinct tracks, library tracks and tracks only logged as text alike.
        $plays = Play::query()
            ->where('device_id', $this->device->id)
            ->where(fn ($q) => $q->whereNotNull('track_id')->orWhereNotNull('track_name'))
            ->with(['track.artist', 'track.album'])
            ->orderByDesc('played_at')
            ->limit(100)
            ->get()
            ->unique(fn (Play $play) => $play->track_id ?? 'text:'.mb_strtolower($play->artist_name.'|'.$play->track_name))
            ->take(10)
            ->values();

        return view('livewire.device-history', ['plays' => $plays]);
    }
}
