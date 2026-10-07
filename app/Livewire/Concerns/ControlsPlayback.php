<?php

namespace App\Livewire\Concerns;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceVolume;
use App\Domain\Device\SpotifyRouting;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

/**
 * Transport, seek, volume and mute for a Livewire component about one device (the device card,
 * the sticky player bar). A failing command is shown as `$controlError`.
 */
trait ControlsPlayback
{
    public ?string $controlError = null;

    /** The device the controls act on; null while there is none (nothing is pinned). */
    abstract protected function playbackDevice(): ?Device;

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
        $device = $this->playbackDevice();
        if ($device === null) {
            return;
        }

        $this->attempt(function () use ($device, $volume) {
            $level = DeviceVolume::set($device, $volume);

            if ($level !== null && property_exists($this, 'volume')) {
                $this->volume = $level;
            }
        });
    }

    public function toggleMute(): void
    {
        $device = $this->playbackDevice();
        if ($device === null) {
            return;
        }

        $muted = $this->isMuted($device);
        $this->control(VolumeControlInterface::class, fn ($d) => $muted ? $d->unmute() : $d->mute(), plain: true);

        if ($this->controlError === null) {
            $this->rememberMuted($device, !$muted);
        }
    }

    protected function isMuted(Device $device): bool
    {
        return Volume::getMuted($device->id) ?? false;
    }

    protected function rememberMuted(Device $device, bool $muted): void
    {
        Volume::updateMuted($device->id, $muted);
    }

    /**
     * Runs a command on the device's driver for $contract (the Spotify driver for playback on a speaker
     * Spotify is routed to); a device without that capability is ignored.
     *
     * @param  bool  $plain  use the device's own driver (volume stays with the speaker) instead of Spotify routing
     */
    protected function control(string $contract, \Closure $apply, bool $plain = false): void
    {
        $device = $this->playbackDevice();
        if ($device === null) {
            return;
        }

        $this->attempt(function () use ($device, $contract, $apply, $plain) {
            $driver = $plain ? $device->driver : SpotifyRouting::driverFor($device, $contract);

            if ($driver instanceof $contract) {
                $apply($driver);
            }
        });
    }

    private function attempt(\Closure $command): void
    {
        try {
            $command();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }
}
