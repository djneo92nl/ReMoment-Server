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
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Support\SelectedDevice;
use Livewire\Component;

/** The sticky bottom player: the pinned device's now playing, in the artwork's colors, with its controls. */
class PlayerBar extends Component
{
    public ?string $controlError = null;

    public function play(): void
    {
        $this->control(MediaControlsInterface::class, fn ($d) => $d->play());
    }

    public function pause(): void
    {
        $this->control(MediaControlsInterface::class, fn ($d) => $d->pause());
    }

    public function next(): void
    {
        $this->control(MediaControlsInterface::class, fn ($d) => $d->next());
    }

    public function previous(): void
    {
        $this->control(MediaControlsInterface::class, fn ($d) => $d->previous());
    }

    public function seek(int $seconds): void
    {
        $this->control(SeekInterface::class, fn ($d) => $d->seek($seconds));
    }

    public function setVolume(int $volume): void
    {
        $device = $this->device();
        $this->control(VolumeControlInterface::class, fn ($d) => $d->setVolume(max(0, min(100, $volume))), plain: true);
        if ($device) {
            Volume::updateVolume($device->id, max(0, min(100, $volume)));
        }
    }

    public function toggleMute(): void
    {
        $device = $this->device();
        $muted = $device ? (Volume::getMuted($device->id) ?? false) : false;
        $this->control(VolumeControlInterface::class, fn ($d) => $muted ? $d->unmute() : $d->mute(), plain: true);
        if ($device) {
            Volume::updateMuted($device->id, !$muted);
        }
    }

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

    private function device(): ?Device
    {
        return app(SelectedDevice::class)->device();
    }

    /** @param  bool  $plain  use the device's own driver (volume stays with the speaker) instead of Spotify routing */
    private function control(string $contract, \Closure $apply, bool $plain = false): void
    {
        $device = $this->device();
        if ($device === null) {
            return;
        }

        try {
            $driver = $plain ? $device->driver : SpotifyRouting::driverFor($device, $contract);
            if (!($driver instanceof $contract)) {
                return;
            }
            $apply($driver);
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
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
            'volume' => Volume::getVolume($device->id) ?: null,
            'muted' => Volume::getMuted($device->id) ?? false,
            'group' => $this->multiroomGroup($device),
        ]);
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
