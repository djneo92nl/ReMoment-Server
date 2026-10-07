<?php

namespace App\Livewire;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\Cache\MultiRoom;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Livewire\Concerns\ControlsPlayback;
use App\Livewire\Concerns\ManagesMultiroom;
use App\Models\Device;
use App\Support\SelectedDevice;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/** The sticky bottom player: the pinned device's now playing, in the artwork's colors, with its controls. */
class PlayerBar extends Component
{
    use ControlsPlayback;
    use ManagesMultiroom;

    public function toggleShuffle(): void
    {
        $device = $this->device();
        if (!$device) {
            return;
        }
        $modesId = SpotifyRouting::modesDeviceId($device);
        $modes = Modes::get($modesId);
        $shuffle = !($modes->shuffle ?? false);
        $this->control(ShuffleInterface::class, fn ($d) => $d->setShuffle($shuffle));
        $this->recordModes($modesId, $modes->withShuffle($shuffle));
    }

    public function cycleRepeat(): void
    {
        $device = $this->device();
        if (!$device) {
            return;
        }
        $modesId = SpotifyRouting::modesDeviceId($device);
        $modes = Modes::get($modesId);
        $repeat = match ($modes->repeat) {
            RepeatMode::Off, null => RepeatMode::All,
            RepeatMode::All => RepeatMode::One,
            RepeatMode::One => RepeatMode::Off,
        };
        $this->control(RepeatInterface::class, fn ($d) => $d->setRepeat($repeat));
        $this->recordModes($modesId, $modes->withRepeat($repeat));
    }

    private function recordModes(int $modesId, \App\Domain\Device\PlaybackModes $modes): void
    {
        if ($this->controlError === null) {
            event(new PlaybackModesUpdated((string) $modesId, $modes, routed: SpotifyRouting::routedDeviceId() === $modesId));
        }
    }

    protected function multiroomDevice(): Device
    {
        return $this->device() ?? throw new \LogicException('No player is pinned.');
    }

    protected function playbackDevice(): ?Device
    {
        return $this->device();
    }

    private function device(): ?Device
    {
        return app(SelectedDevice::class)->device();
    }

    public function render()
    {
        $device = $this->device();
        if ($device === null) {
            return view('livewire.player-bar', ['device' => null]);
        }

        $state = DeviceCache::isListenerRunning($device->id) ? $device->state : State::Unreachable;
        $nowPlaying = DeviceCache::getNowPlaying($device->id);
        $capabilities = DeviceCapabilities::for($device);
        $modes = Modes::get(SpotifyRouting::modesDeviceId($device));

        return view('livewire.player-bar', [
            'device' => $device,
            'state' => $state,
            'nowPlaying' => $nowPlaying,
            'artwork' => $nowPlaying ? NowPlayingArtwork::resolve($nowPlaying) : null,
            'capabilities' => $capabilities,
            'modes' => $modes,
            'volume' => $this->volume($device, $capabilities, $state),
            'muted' => Volume::getMuted($device->id) ?? false,
            'group' => $this->multiroomGroup($device),
            'supportsMultiRoom' => in_array('multi_room', $capabilities, true) && $state !== State::Unreachable,
        ]);
    }

    /**
     * The device's volume: from the cache the listener keeps, else asked from the device once (the cache
     * expires after a few minutes without a change). Null while unknown.
     *
     * @param  string[]  $capabilities
     */
    private function volume(Device $device, array $capabilities, State $state): ?int
    {
        $volume = Volume::getVolume($device->id);
        if ($volume !== false || !in_array('volume_control', $capabilities, true) || $state === State::Unreachable) {
            return $volume !== false ? (int) $volume : null;
        }

        $missKey = "player_bar_volume_miss_{$device->id}";
        if (Cache::has($missKey)) {
            return null;
        }

        try {
            $driver = $device->driver;
            $volume = $driver instanceof VolumeControlInterface ? (int) $driver->getVolume() : null;
        } catch (\Throwable) {
            $volume = null;
        }

        if ($volume === null) {
            Cache::put($missKey, true, 30);

            return null;
        }

        Volume::updateVolume($device->id, $volume);

        return $volume;
    }

    /**
     * Names of every player in the pinned device's multiroom session (the device itself first),
     * or [] when it isn't in one. A listener knows only its host, so the rest comes from the host's status.
     *
     * @return string[]
     */
    private function multiroomGroup(Device $device): array
    {
        $status = MultiRoom::get($device->id)?->resolve($device);
        if ($status === null || $status['role'] === 'standalone') {
            return [];
        }

        $others = collect($status['listeners']);
        if ($status['host'] !== null) {
            $host = Device::find($status['host']['id']);
            $hostListeners = $host ? (MultiRoom::get($host->id)?->resolve($host)['listeners'] ?? []) : [];
            $others = collect([$status['host']])->merge($hostListeners)->reject(fn ($d) => $d['id'] === $device->id);
        }

        return $others->unique('id')->pluck('name')->prepend($device->device_name)->values()->all();
    }
}
