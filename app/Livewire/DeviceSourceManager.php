<?php

namespace App\Livewire;

use App\Integrations\Contracts\SourceActivationInterface;
use App\Models\Device;
use App\Models\DeviceSource;
use Livewire\Component;

class DeviceSourceManager extends Component
{
    public Device $device;

    public bool $editMode = false;

    public array $sources = [];

    public bool $supportsActivation = false;

    public function mount(Device $device): void
    {
        $this->device = $device;
        try {
            $this->supportsActivation = $device->driver instanceof SourceActivationInterface;
        } catch (\Throwable) {
            $this->supportsActivation = false;
        }
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
