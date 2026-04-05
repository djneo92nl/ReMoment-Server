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
    public string $search = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public function updatedDeviceId(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function setSource(?string $source): void
    {
        $this->sourceFilter = $source;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->deviceId = null;
        $this->sourceFilter = null;
        $this->search = '';
        $this->dateFrom = '';
        $this->dateTo = '';
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

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('track', function ($tq) use ($search) {
                    $tq->where('name', 'like', "%{$search}%")
                        ->orWhereHas('artist', fn ($aq) => $aq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('album', fn ($aq) => $aq->where('name', 'like', "%{$search}%"));
                })->orWhere('radio_name', 'like', "%{$search}%")
                  ->orWhere('source_name', 'like', "%{$search}%");
            });
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('played_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('played_at', '<=', $this->dateTo);
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
