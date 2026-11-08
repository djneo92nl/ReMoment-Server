<?php

namespace App\Livewire\Developer;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Models\Device;
use App\Models\Play;
use Livewire\Component;

/** /settings/developer: push a made-up now playing, progress, state or volume through the same events a listener fires. */
class SimulateNowPlaying extends Component
{
    public ?int $deviceId = null;

    public string $track = 'Test Track';

    public string $artist = 'Test Artist';

    public string $album = 'Test Album';

    public int $duration = 213;

    public string $image = '';

    public string $state = 'playing';

    public int $progress = 50;

    public int $volume = 30;

    public ?string $message = null;

    /** Fills the form from a play in the history, so a real track can be replayed on a device. */
    public function fromHistory(int $playId): void
    {
        $play = Play::find($playId);

        if (!$play) {
            return;
        }

        $this->track = (string) $play->track_name;
        $this->artist = (string) $play->artist_name;
        $this->album = (string) $play->album_name;
        $this->duration = (int) ($play->duration ?: 213);
        $this->image = (string) $play->image_url;
        $this->progress = 0;
        $this->message = "Filled from \"{$this->track}\".";
    }

    public function nowPlaying(): void
    {
        if (!$this->device()) {
            return;
        }

        $images = $this->image !== '' ? [$this->image] : [];
        $artist = new ArtistData($this->artist);
        $playing = new NowPlaying(
            track: new TrackData('dev:'.md5($this->track.$this->artist), $this->track, 'dev', $artist, $this->duration, $images),
            album: new AlbumData($this->album, $images, $artist),
            state: $this->state,
            position: (int) round($this->duration * $this->progress / 100),
            type: 'music',
            platform: 'media',
        );

        DeviceCache::updateState($this->deviceId, State::tryFrom($this->state) ?? State::Playing);
        DeviceCache::updateNowPlaying($this->deviceId, $playing);
        event(new NowPlayingUpdated((string) $this->deviceId, $playing, 'media'));
        $this->message = "Now playing sent for device {$this->deviceId}.";
    }

    public function sendProgress(): void
    {
        if ($this->device()) {
            event(new ProgressUpdated((string) $this->deviceId, max(0, min(100, $this->progress))));
            $this->message = "Progress {$this->progress}% sent.";
        }
    }

    public function sendState(): void
    {
        if ($device = $this->device()) {
            $state = State::tryFrom($this->state) ?? State::Playing;
            $previous = DeviceCache::getState($device->id);
            DeviceCache::updateState($device->id, $state);
            event(new DeviceStateChanged($device->id, $state, $previous));
            $this->message = "State {$state->value} sent.";
        }
    }

    public function sendVolume(): void
    {
        if ($this->device()) {
            event(new VolumeUpdated((string) $this->deviceId, max(0, min(100, $this->volume))));
            $this->message = "Volume {$this->volume} sent.";
        }
    }

    public function end(): void
    {
        if ($this->device()) {
            DeviceCache::forgetNowPlaying($this->deviceId);
            event(new NowPlayingEnded((string) $this->deviceId));
            $this->message = 'Now playing ended.';
        }
    }

    private function device(): ?Device
    {
        $device = $this->deviceId ? Device::find($this->deviceId) : null;

        if (!$device) {
            $this->message = 'Choose a device first.';
        }

        return $device;
    }

    public function render()
    {
        return view('livewire.developer.simulate-now-playing', [
            'devices' => Device::orderBy('device_name')->get(),
            'states' => array_column(State::cases(), 'value'),
            'history' => Play::whereNotNull('track_name')->latest('played_at')->limit(25)->get(['id', 'track_name', 'artist_name']),
        ]);
    }
}
