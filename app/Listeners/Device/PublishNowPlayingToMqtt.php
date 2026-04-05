<?php

namespace App\Listeners\Device;

use App\Domain\Artwork\ArtworkCache;
use App\Events\Device\NowPlayingUpdated;
use App\Services\MqttService;

class PublishNowPlayingToMqtt
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected MqttService $mqttService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(NowPlayingUpdated $event): void
    {
        $deviceId = $event->deviceId;
        $nowPlaying = $event->nowPlaying;

        $data = [
            'track' => $nowPlaying->track?->name,
            'artist' => $nowPlaying->track?->artist?->name,
        ];

        $url = ArtworkCache::extractImageUrl($nowPlaying);
        $artwork = $url ? ArtworkCache::get($url) : null;
        if ($artwork !== null) {
            $data['artwork'] = $artwork;
        }

        $this->mqttService->publish("remoment/player/{$deviceId}/data", json_encode($data));
    }
}
