<?php

namespace App\Livewire;

use App\Domain\Device\Capabilities;
use App\Domain\Playback\AnnouncementClips;
use App\Domain\Playback\Announcer;
use App\Models\Device;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Settings › Announcements: the clips an announcement can play (upload, remove) and a test on a speaker. Admin only. */
class AnnouncementManager extends Component
{
    use WithFileUploads;

    public $file = null;

    public string $label = '';

    public ?int $deviceId = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function upload(): void
    {
        abort_unless(auth()->check(), 403);
        $this->notice = $this->problem = null;

        $this->validate(['file' => ['required', 'file', 'max:20480'], 'label' => ['nullable', 'string', 'max:64']]);

        try {
            $clip = AnnouncementClips::add($this->file, $this->label);
            $this->notice = "Added: {$clip['label']}";
            $this->reset('file', 'label');
        } catch (\RuntimeException $e) {
            $this->problem = $e->getMessage();
        }
    }

    public function remove(string $name): void
    {
        abort_unless(auth()->check(), 403);

        AnnouncementClips::remove($name);
    }

    public function test(string $name): void
    {
        abort_unless(auth()->check(), 403);
        $this->notice = $this->problem = null;

        $clip = AnnouncementClips::find($name);
        $device = $this->deviceId ? Device::find($this->deviceId) : null;
        if (!$clip || !$device) {
            $this->problem = 'Choose a speaker first.';

            return;
        }

        try {
            app(Announcer::class)->announce($device, $clip);
            $this->notice = "Playing on {$device->device_name}.";
        } catch (\Throwable $e) {
            $this->problem = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.announcement-manager', [
            'clips' => AnnouncementClips::all(),
            'devices' => Device::orderBy('device_name')->get()
                ->filter(fn (Device $device) => in_array('stream_url', Capabilities::forDriver($device->device_driver), true))->values(),
        ]);
    }
}
