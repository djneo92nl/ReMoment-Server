<?php

namespace App\Livewire\Developer;

use App\Domain\Developer\CallTimings;
use App\Domain\Developer\QualityPlayer;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Storage\FileIndexer;
use App\Domain\Storage\Proxies;
use App\Models\Device;
use App\Models\Media\Track;
use Livewire\Component;

/** /settings/developer: play one track at a chosen rung of a quality ladder (the original or a transcode) on a player. */
class QualityTest extends Component
{
    public string $search = '';

    public ?int $trackId = null;

    public ?int $deviceId = null;

    /** Result of the last play: ['ok' => bool, 'message' => string, 'url' => ?string]. */
    public ?array $result = null;

    public function choose(int $trackId): void
    {
        $this->trackId = $trackId;
        $this->result = null;
    }

    public function generate(string $profile): void
    {
        $track = $this->track();

        if (!$track) {
            return;
        }

        try {
            QualityPlayer::prepare($track, $profile);
            $this->result = ['ok' => true, 'message' => "{$profile} is ready.", 'url' => null];
        } catch (\Throwable $e) {
            $this->result = ['ok' => false, 'message' => $e->getMessage(), 'url' => null];
        }
    }

    /** Plays the track on the device; $profile null is the original file. */
    public function play(?string $profile = null): void
    {
        $track = $this->track();
        $device = $this->deviceId ? Device::find($this->deviceId) : null;

        if (!$track || !$device) {
            $this->result = ['ok' => false, 'message' => 'Choose a track and a device first.', 'url' => null];

            return;
        }

        try {
            $played = QualityPlayer::play($device, $track, $profile);
            $this->result = ['ok' => true, 'message' => 'Sent to '.$device->device_name.' as '.($profile ?? 'the original')." ({$played['ms']} ms).", 'url' => $played['url']];
        } catch (\Throwable $e) {
            CallTimings::record($device->id, 'play '.($profile ?? 'original'), 0, false);
            $this->result = ['ok' => false, 'message' => class_basename($e).': '.$e->getMessage(), 'url' => null];
        }
    }

    private function track(): ?Track
    {
        return $this->trackId ? Track::with(['artist', 'album', 'trackFiles.file'])->find($this->trackId) : null;
    }

    public function render()
    {
        $term = trim($this->search);

        $tracks = strlen($term) < 2 ? collect() : Track::with('artist')
            ->whereHas('metadata', fn ($q) => $q->where('key', 'dlna_url')->where('source', FileIndexer::URL_SOURCE))
            ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhereHas('artist', fn ($a) => $a->where('name', 'like', "%{$term}%")))
            ->orderBy('name')->limit(8)->get();

        $track = $this->track();
        $original = $track ? Proxies::original($track) : null;

        $rungs = [];
        foreach (QualityPlayer::ladder() as $rung) {
            $path = $original ? Proxies::path($original, $rung['name']) : null;
            $ready = $path && Proxies::disk()->exists($path);
            $rungs[] = $rung + ['ready' => $ready, 'size' => $ready ? Proxies::disk()->size($path) : null];
        }

        return view('livewire.developer.quality-test', [
            'tracks' => $tracks,
            'track' => $track,
            'original' => $original,
            'rungs' => collect($rungs)->groupBy('group'),
            'devices' => Device::orderBy('device_name')->get()
                ->filter(fn (Device $d) => in_array('library_playback', DeviceCapabilities::for($d), true))->values(),
            'canTranscode' => Proxies::enabled(),
        ]);
    }
}
