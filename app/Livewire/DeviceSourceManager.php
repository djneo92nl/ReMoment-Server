<?php

namespace App\Livewire;

use App\Domain\Device\SourceSync;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VideoDeviceInterface;
use App\Models\Device;
use App\Models\DeviceSource;
use Livewire\Component;

class DeviceSourceManager extends Component
{
    public Device $device;

    public bool $editMode = false;

    public array $sources = [];

    public bool $supportsActivation = false;

    /** A TV: the inline card shows inputs and apps as grids instead of one list. */
    public bool $tv = false;

    /** Live data per source id (`active`, `input`); empty when the device is unreachable. */
    public array $live = [];

    public bool $showAll = false;

    public function mount(Device $device): void
    {
        $this->device = $device;
        try {
            $this->supportsActivation = $device->driver instanceof SourceActivationInterface;
            $this->tv = $device->driver instanceof VideoDeviceInterface;
        } catch (\Throwable) {
            $this->supportsActivation = false;
            $this->tv = false;
        }
        $this->syncWhenEmpty();
        $this->loadLive();
        $this->loadSources();
    }

    public function render()
    {
        return view('livewire.device-source-manager');
    }

    public function toggleHidden(int $id): void
    {
        $source = $this->ownSource($id);
        $source?->update(['hidden' => !$source->hidden]);
        $this->loadSources();
    }

    public function moveUp(int $id): void
    {
        $this->swap($id, direction: -1);
    }

    public function moveDown(int $id): void
    {
        $this->swap($id, direction: 1);
    }

    public function activateSource(int $id): void
    {
        $source = $this->ownSource($id);
        if (!$source || !$this->supportsActivation) {
            return;
        }
        try {
            $driver = $this->device->driver;
            if ($driver instanceof SourceActivationInterface) {
                $driver->activateSource($source->source_id);
            }
        } catch (\Throwable) {
        }
        $this->loadLive();
    }

    /** Which source is selected and which physical input each has; the stored rows only hold preferences. */
    private function loadLive(): void
    {
        $this->live = [];

        try {
            $driver = $this->device->driver;
            if (!$driver instanceof SourcesInterface) {
                return;
            }
            foreach ($driver->getSources() as $s) {
                $this->live[$s->sourceId] = ['active' => $s->active, 'input' => $s->input, 'signal' => $s->signal];
            }
        } catch (\Throwable) {
            // Unreachable device: the list still renders from the stored rows, with no active row.
        }
    }

    /**
     * Sources are stored by the weekly `device:sync-sources`, so a device added since
     * (or one whose driver gained sources) has none yet: fetch them on first view.
     */
    private function syncWhenEmpty(): void
    {
        if ($this->device->deviceSources()->exists()) {
            return;
        }

        try {
            $driver = $this->device->driver;
            if ($driver instanceof SourcesInterface) {
                SourceSync::sync($this->device, $driver);
            }
        } catch (\Throwable) {
            // Unreachable device: the card shows its empty state until the next visit.
        }
    }

    private function swap(int $id, int $direction): void
    {
        $ids = $this->device->deviceSources()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->toArray();

        $pos = array_search($id, $ids);
        $target = $pos + $direction;

        if ($pos === false || $target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];

        foreach ($ids as $order => $sourceId) {
            DeviceSource::where('id', $sourceId)->update(['sort_order' => $order]);
        }

        $this->loadSources();
    }

    private function ownSource(int $id): ?DeviceSource
    {
        return $this->device->deviceSources()->find($id);
    }

    private function loadSources(): void
    {
        $this->sources = $this->device->deviceSources()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'source_id' => $s->source_id,
                'active' => (bool) ($this->live[$s->source_id]['active'] ?? false),
                'input' => $this->live[$s->source_id]['input'] ?? null,
                'signal' => $this->live[$s->source_id]['signal'] ?? null,
                'name' => $s->friendly_name,
                'type' => $s->source_type,
                'category' => $s->category,
                'hidden' => (bool) $s->hidden,
                'borrowed' => (bool) $s->borrowed,
                'provider_name' => $s->provider_name,
            ])
            ->all();
    }
}
