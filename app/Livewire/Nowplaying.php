<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Library\PlayedTrackAdder;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Media\Track;
use Livewire\Component;

class Nowplaying extends Component
{
    public $device;

    public $volume = 2; // default volume 0-100

    public bool $listenerRunning = false;

    public bool $supportsSeek = false;

    public bool $muted = false;

    public ?string $controlError = null;

    public ?string $currentTrackId = null;

    public ?string $lyricsPlain = null;

    public ?string $lyricsSynced = null;

    /** Set once the playing track was added from here: the page to open it on. */
    public ?string $addedUrl = null;

    public ?string $addFailure = null;

    public function mount($device)
    {
        $this->device = $device;
        try {
            $driver = $device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $this->volume = $driver->getVolume();
            }
            $this->supportsSeek = SpotifyRouting::driverFor($device, SeekInterface::class) instanceof SeekInterface;
        } catch (\Throwable) {
            $this->volume = 0;
        }
        $this->listenerRunning = DeviceCache::isListenerRunning($device->id);
    }

    public function render()
    {
        $this->listenerRunning = DeviceCache::isListenerRunning($this->device->id);

        $cachedNowPlaying = DeviceCache::getNowPlaying($this->device->id);
        $trackSignature = ($cachedNowPlaying?->track?->id ?? '').'|'.($cachedNowPlaying?->track?->name ?? '');

        if ($trackSignature !== $this->currentTrackId) {
            $this->currentTrackId = $trackSignature;
            $this->lyricsPlain = null;
            $this->lyricsSynced = null;
            $this->addedUrl = null;
            $this->addFailure = null;

            $track = $this->resolveTrack($cachedNowPlaying);
            if ($track) {
                $this->lyricsPlain = $track->lyricsPlain();
                $this->lyricsSynced = $track->lyricsSynced();
            }
        }

        // A playing track the library doesn't have (an external service, radio with a title) can be added.
        $canAdd = $this->addedUrl === null
            && $cachedNowPlaying?->track !== null
            && !PlayedTrackAdder::inLibrary($cachedNowPlaying);

        return view('livewire.nowplaying', ['canAdd' => $canAdd]);
    }

    public function updatedVolume($value)
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $driver->setVolume($value);
            }
        } catch (\Throwable) {
        }
        $this->volume = $value;
    }

    public function seek(int $seconds)
    {
        try {
            $driver = SpotifyRouting::driverFor($this->device, SeekInterface::class);
            if ($driver instanceof SeekInterface) {
                $driver->seek($seconds);
            }
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function toggleMute()
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $this->muted ? $driver->unmute() : $driver->mute();
                $this->muted = !$this->muted;
            }
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function play()
    {
        try {
            SpotifyRouting::driverFor($this->device, MediaControlsInterface::class)->play();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function pause()
    {
        try {
            SpotifyRouting::driverFor($this->device, MediaControlsInterface::class)->pause();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function next()
    {
        try {
            SpotifyRouting::driverFor($this->device, MediaControlsInterface::class)->next();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function previous()
    {
        try {
            SpotifyRouting::driverFor($this->device, MediaControlsInterface::class)->previous();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    private function resolveTrack(?\App\Domain\Media\NowPlaying $nowPlaying): ?Track
    {
        if ($nowPlaying === null) {
            return null;
        }

        $externalId = $nowPlaying->track?->id;
        $name = $nowPlaying->track?->name;
        $artist = $nowPlaying->track?->artist?->name;

        $withLyrics = fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced']);

        if ($externalId) {
            $track = Track::where('external_id', $externalId)->with(['metadata' => $withLyrics])->first();
            if ($track) {
                return $track;
            }
        }

        if ($name && $artist) {
            return Track::where('name', $name)
                ->whereHas('artist', fn ($q) => $q->where('name', $artist))
                ->whereHas('metadata', fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced'])->where('value', '!=', ''))
                ->with(['metadata' => $withLyrics])
                ->first();
        }

        return null;
    }

    public function addToLibrary(): void
    {
        $nowPlaying = DeviceCache::getNowPlaying($this->device->id);
        $track = $nowPlaying ? PlayedTrackAdder::add($nowPlaying) : null;

        if ($track === null) {
            $this->addFailure = 'Nothing to add: this track has no artist or name.';

            return;
        }

        $this->addFailure = null;
        $this->addedUrl = $track->album_id ? route('albums.show', $track->album_id).'#track-'.$track->id : route('artists.show', $track->artist_id);
    }

    public function standby()
    {
        try {
            $driver = $this->device->driver;
            if (method_exists($driver, 'standby')) {
                $driver->standby();
            }
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }
}
