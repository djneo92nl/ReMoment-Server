<?php

namespace App\Livewire;

use App\Domain\Multiroom\Presets;
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
        return view('livewire.multiroom-presets', [
            'presets' => Presets::all()->map(fn (MultiroomPreset $preset) => [
                'id' => $preset->id,
                'name' => $preset->name,
                'devices' => Presets::toArray($preset)['devices'],
            ]),
            'multiroomDevices' => Presets::multiroomDevices(),
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

        Presets::create($this->newName, $this->newDeviceIds);

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

        $result = Presets::activate($preset);

        if ($result['too_few']) {
            $this->setFeedback('Preset needs at least 2 devices.', true);
        } elseif ($result['failed']) {
            $this->setFeedback('Some devices failed: '.collect($result['failed'])->map(fn ($f) => $f['device']->device_name.': '.$f['message'])->implode('; '), true);
        } else {
            $this->setFeedback('Preset "'.$preset->name.'" activated.');
        }
    }

    public function delete(int $presetId): void
    {
        if ($preset = MultiroomPreset::find($presetId)) {
            Presets::delete($preset);
        }
        $this->setFeedback('Preset deleted.');
    }

    private function setFeedback(string $message, bool $isError = false): void
    {
        $this->feedback = $message;
        $this->feedbackIsError = $isError;
    }
}
