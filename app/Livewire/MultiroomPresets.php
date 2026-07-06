<?php

namespace App\Livewire;

use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;
use App\Models\MultiroomPreset;
use Livewire\Component;

class MultiroomPresets extends Component
{
    public bool $creating = false;

    public string $newName = '';

    /** @var array<int> */
    public array $newDeviceIds = [];

    public ?string $feedback = null;

    public bool $feedbackIsError = false;

    public function render()
    {
        $presets = MultiroomPreset::all()->map(function (MultiroomPreset $preset) {
            $devices = Device::whereIn('id', $preset->device_ids)->get(['id', 'device_name']);

            return [
                'id' => $preset->id,
                'name' => $preset->name,
                'devices' => $devices->map(fn ($d) => ['id' => $d->id, 'name' => $d->device_name])->all(),
            ];
        });

        $multiroomDevices = Device::all()->filter(function (Device $device) {
            return is_a($device->device_driver, MultiRoomInterface::class, true);
        })->map(fn ($d) => ['id' => $d->id, 'name' => $d->device_name])
            ->values();

        return view('livewire.multiroom-presets', [
            'presets' => $presets,
            'multiroomDevices' => $multiroomDevices,
        ]);
    }

    public function create(): void
    {
        $this->validate([
            'newName' => 'required|string|max:100',
            'newDeviceIds' => 'required|array|min:2',
        ], [
            'newDeviceIds.required' => 'Select at least 2 devices.',
            'newDeviceIds.min' => 'Select at least 2 devices.',
        ]);

        MultiroomPreset::create([
            'name' => trim($this->newName),
            'device_ids' => array_values(array_map('intval', $this->newDeviceIds)),
        ]);

        $this->newName = '';
        $this->newDeviceIds = [];
        $this->creating = false;
        $this->setFeedback('Preset saved.');
    }

    public function activate(int $presetId): void
    {
        $preset = MultiroomPreset::find($presetId);
        if (!$preset) {
            return;
        }

        $deviceMap = Device::whereIn('id', $preset->device_ids)->get()->keyBy('id');
        $devices = collect($preset->device_ids)->map(fn ($id) => $deviceMap->get($id))->filter()->values();
        if ($devices->count() < 2) {
            $this->setFeedback('Preset needs at least 2 devices.', true);

            return;
        }

        $host = $devices->first();
        $errors = [];

        foreach ($devices->skip(1) as $guest) {
            try {
                $driver = $guest->driver;
                if ($driver instanceof MultiRoomInterface) {
                    $driver->joinSession($host);
                }
            } catch (\Throwable $e) {
                $errors[] = $guest->device_name.': '.$e->getMessage();
            }
        }

        if ($errors) {
            $this->setFeedback('Some devices failed: '.implode('; ', $errors), true);
        } else {
            $this->setFeedback('Preset "'.$preset->name.'" activated.');
        }
    }

    public function delete(int $presetId): void
    {
        MultiroomPreset::find($presetId)?->delete();
        $this->setFeedback('Preset deleted.');
    }

    private function setFeedback(string $message, bool $isError = false): void
    {
        $this->feedback = $message;
        $this->feedbackIsError = $isError;
    }
}
