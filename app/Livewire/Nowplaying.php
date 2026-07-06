<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCache;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Media\Track;
use Livewire\Component;

class Nowplaying extends Component
{
    public $device;

    public $volume = 2; // default volume 0-100

    public bool $listenerRunning = false;

    public ?string $controlError = null;

    public ?string $currentTrackId = null;

    public ?string $lyricsPlain = null;

    public ?string $lyricsSynced = null;

    public function mount($device)
    {
        $this->device = $device;
        try {
            $driver = $device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $this->volume = $driver->getVolume();
            }
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

            $track = $this->resolveTrack($cachedNowPlaying);
            if ($track) {
                $this->lyricsPlain = $track->lyricsPlain();
                $this->lyricsSynced = $track->lyricsSynced();
            }
        }

        return view('livewire.nowplaying');
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

    public function play()
    {
        try {
            $this->device->driver->play();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function pause()
    {
        try {
            $this->device->driver->pause();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function next()
    {
        try {
            $this->device->driver->next();
            $this->controlError = null;
        } catch (\Throwable $e) {
            $this->controlError = 'Command failed: '.$e->getMessage();
        }
    }

    public function previous()
    {
        try {
            $this->device->driver->previous();
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
