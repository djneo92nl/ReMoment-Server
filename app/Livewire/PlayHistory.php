<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\Play;
use Livewire\Component;
use Livewire\WithPagination;

class PlayHistory extends Component
{
    use WithPagination;

    public ?int $deviceId = null;
    public ?string $sourceFilter = null;

    public function updatedDeviceId(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function setSource(?string $source): void
    {
        $this->sourceFilter = $source;
        $this->resetPage();
    }

    public function render()
    {
        $query = Play::query()
            ->with(['device', 'track.artist', 'track.album'])
            ->orderByDesc('played_at');

        if ($this->deviceId) {
            $query->where('device_id', $this->deviceId);
        }

        if ($this->sourceFilter !== null) {
            $query->where('source_type', $this->sourceFilter);
        }

        $plays = $query->paginate(50);

        $devices = Device::orderBy('device_name')->get();

        $sourceTypes = Play::query()
            ->selectRaw('DISTINCT source_type')
            ->whereNotNull('source_type')
            ->orderBy('source_type')
            ->pluck('source_type');

        return view('livewire.play-history', [
            'plays'       => $plays,
            'devices'     => $devices,
            'sourceTypes' => $sourceTypes,
        ]);
    }
}
