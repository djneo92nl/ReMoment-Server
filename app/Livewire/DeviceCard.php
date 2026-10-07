<?php

namespace App\Livewire;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\SpotifyRouting;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Livewire\Concerns\ManagesMultiroom;
use App\Livewire\Concerns\ManagesTrackExtras;
use App\Models\Device;
use App\Models\Play;
use App\Models\RadioStation;
use Livewire\Component;

class DeviceCard extends Component
{
    use ManagesMultiroom;
    use ManagesTrackExtras;

    public Device $device;

    public int $volume = 0;

    public bool $listenerRunning = false;

    public bool $standalone = false; // disables md:col-span-2 (use on show page)

    public bool $supportsMultiRoom = false;

    public bool $supportsSourceActivation = false;

    public bool $supportsRadio = false;

    public bool $supportsSeek = false;

    public bool $muted = false;

    public array $quickSources = [];

    public ?array $lastRadioStation = null;

    public function mount(Device $device): void
    {
        $this->device = $device;
        $this->refresh();
        try {
            $driver = $device->driver;
            $this->supportsMultiRoom = $driver instanceof MultiRoomInterface;
            $this->supportsSourceActivation = $driver instanceof SourceActivationInterface;
            $this->supportsRadio = $driver instanceof RadioControlInterface;
            $this->supportsSeek = SpotifyRouting::driverFor($device, SeekInterface::class) instanceof SeekInterface;
            // Asking the device costs a network round trip, so only the
            // single-device page reads it; grid cards start unmuted.
            if ($this->standalone && $driver instanceof VolumeControlInterface) {
                $this->muted = $driver->isMuted();
            }
        } catch (\Throwable) {
            $this->supportsMultiRoom = false;
            $this->supportsSourceActivation = false;
            $this->supportsRadio = false;
        }

        if ($this->supportsSourceActivation) {
            $streamingTypes = ['spotify', 'deezer', 'tidal', 'qobuz'];
            $this->quickSources = $device->deviceSources()
                ->where('borrowed', false)
                ->where('hidden', false)
                ->whereNotIn('source_type', $streamingTypes)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->source_id,
                    'name' => $s->friendly_name,
                    'type' => $s->source_type,
                    'category' => $s->category,
                ])
                ->values()
                ->all();
        }

        if ($this->supportsRadio) {
            $lastPlay = Play::where('device_id', $device->id)
                ->where('source_type', 'radio')
                ->whereNotNull('radio_station_id')
                ->with('radioStation')
                ->latest('played_at')
                ->first();

            if ($lastPlay?->radioStation) {
                $this->lastRadioStation = [
                    'id' => $lastPlay->radioStation->id,
                    'name' => $lastPlay->radioStation->name,
                ];
            }
        }
    }

    public function render()
    {
        $this->refresh();

        // Lyrics and "Add to library" only on the single-device page, not for every card in the grid.
        $canAdd = $this->standalone && $this->refreshTrackExtras(DeviceCache::getNowPlaying($this->device->id));

        return view('livewire.device-card', ['canAdd' => $canAdd]);
    }

    public function play(): void
    {
        $this->withPlaybackDriver(fn ($d) => $d->play());
    }

    public function pause(): void
    {
        $this->withPlaybackDriver(fn ($d) => $d->pause());
    }

    public function next(): void
    {
        $this->withPlaybackDriver(fn ($d) => $d->next());
    }

    public function previous(): void
    {
        $this->withPlaybackDriver(fn ($d) => $d->previous());
    }

    public function standby(): void
    {
        $this->withDriver(fn ($d) => $d->standby());
    }

    public function seek(int $seconds): void
    {
        try {
            $driver = SpotifyRouting::driverFor($this->device, SeekInterface::class);
            if ($driver instanceof SeekInterface) {
                $driver->seek($seconds);
            }
        } catch (\Throwable) {
        }
    }

    public function toggleMute(): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $this->muted ? $driver->unmute() : $driver->mute();
                $this->muted = !$this->muted;
            }
        } catch (\Throwable) {
        }
    }

    public function playLastRadioStation(): void
    {
        if (!$this->lastRadioStation) {
            return;
        }
        try {
            $driver = $this->device->driver;
            if ($driver instanceof RadioControlInterface) {
                $station = RadioStation::find($this->lastRadioStation['id']);
                if ($station) {
                    $driver->playRadioStation($station);
                }
            }
        } catch (\Throwable) {
        }
    }

    public function activateSource(string $sourceId): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof SourceActivationInterface) {
                $driver->activateSource($sourceId);
            }
        } catch (\Throwable) {
        }
    }

    public function setVolume(int $volume): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $driver->setVolume($volume);
                $this->volume = $volume;
            }
        } catch (\Throwable) {
        }
    }

    protected function multiroomDevice(): Device
    {
        return $this->device;
    }

    protected function trackExtrasDevice(): Device
    {
        return $this->device;
    }

    private function refresh(): void
    {
        $this->listenerRunning = DeviceCache::isListenerRunning($this->device->id);
        $this->volume = (int) (Volume::getVolume($this->device->id) ?: 0);
    }

    /** Transport goes to the Spotify driver while Spotify is routed to this device. */
    private function withPlaybackDriver(callable $callback): void
    {
        try {
            $driver = SpotifyRouting::driverFor($this->device, MediaControlsInterface::class);
            if ($driver instanceof MediaControlsInterface) {
                $callback($driver);
            }
        } catch (\Throwable) {
            // silently ignore driver errors in card context
        }
    }

    private function withDriver(callable $callback): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof MediaControlsInterface) {
                $callback($driver);
            }
        } catch (\Throwable) {
            // silently ignore driver errors in card context
        }
    }
}
