<?php

namespace App\Integrations\Spotify;

use App\Domain\Device\DeviceCache;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Models\Device;
use App\Services\SpotifyTokenService;

class MusicPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface
{
    public function __construct(public Device $device) {}

    public function getCurrentPlayingAttribute(): array
    {
        return DeviceCache::getNowPlaying($this->device->id)?->toArray() ?? [];
    }

    public function play(): void
    {
        $this->api()->play();
    }

    public function pause(): void
    {
        $this->api()->pause();
    }

    public function next(): void
    {
        $this->api()->next();
    }

    public function previous(): void
    {
        $this->api()->previous();
    }

    public function stop(): void
    {
        $this->api()->pause();
    }

    private function api(): \SpotifyWebAPI\SpotifyWebAPI
    {
        return app(SpotifyTokenService::class)->makeApiClient();
    }
}
