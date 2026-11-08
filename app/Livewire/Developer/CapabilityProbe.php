<?php

namespace App\Livewire\Developer;

use App\Domain\Developer\CallTimings;
use App\Domain\Developer\CapabilityProfile;
use App\Domain\Developer\QualityPlayer;
use App\Domain\Device\DeviceCapabilities;
use App\Models\Device;
use Livewire\Component;

/**
 * /settings/developer: walk the quality ladder on one player, listen, and mark each rung ok or broken. The verdicts
 * are kept per device (CapabilityProfile), so the most a player can do is written down.
 */
class CapabilityProbe extends Component
{
    use PicksTrack;

    public ?int $deviceId = null;

    public ?array $result = null;

    public function play(string $profile): void
    {
        $track = $this->track();
        $device = $this->device();

        if (!$track || !$device) {
            $this->result = ['ok' => false, 'message' => 'Choose a track and a device first.'];

            return;
        }

        try {
            $played = QualityPlayer::play($device, $track, $profile);
            $this->result = ['ok' => true, 'message' => "Playing {$profile}. Listen, then mark it (sent in {$played['ms']} ms)."];
        } catch (\Throwable $e) {
            CallTimings::record($device->id, "play {$profile}", 0, false);
            CapabilityProfile::mark($device, $profile, 'broken');
            $this->result = ['ok' => false, 'message' => class_basename($e).': '.$e->getMessage().' — marked broken.'];
        }
    }

    public function mark(string $profile, ?string $verdict): void
    {
        if (($device = $this->device()) && QualityPlayer::ladder() && collect(QualityPlayer::ladder())->contains('name', $profile)) {
            CapabilityProfile::mark($device, $profile, $verdict);
        }
    }

    public function clear(): void
    {
        if ($device = $this->device()) {
            CapabilityProfile::clear($device);
        }
    }

    private function device(): ?Device
    {
        return $this->deviceId ? Device::find($this->deviceId) : null;
    }

    public function render()
    {
        $device = $this->device();

        return view('livewire.developer.capability-probe', [
            'tracks' => $this->foundTracks(),
            'track' => $this->track(),
            'devices' => Device::orderBy('device_name')->get()->filter(fn (Device $d) => in_array('library_playback', DeviceCapabilities::for($d), true))->values(),
            'rungs' => collect(QualityPlayer::ladder())->groupBy('group'),
            'verdicts' => $device ? CapabilityProfile::get($device) : [],
            'summary' => $device ? CapabilityProfile::summary($device) : [],
        ]);
    }
}
