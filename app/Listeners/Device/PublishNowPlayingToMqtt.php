<?php

namespace App\Listeners\Device;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Media\NowPlaying;
use App\Events\Device\NowPlayingUpdated;
use App\Services\MqttService;
use Illuminate\Support\Facades\Cache;

/**
 * `/data` is retained, so a client subscribing later (e.g. after switching
 * device) gets the current track at once. An empty retained payload clears
 * it when the device goes to standby or becomes unreachable.
 */
class PublishNowPlayingToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(NowPlayingUpdated $event): void
    {
        $this->publish((int) $event->deviceId, $event->nowPlaying);
    }

    public function publish(int $deviceId, NowPlaying $nowPlaying): void
    {
        $data = [
            'track' => $nowPlaying->track?->name,
            'artist' => $nowPlaying->track?->artist?->name,
        ];

        $artwork = NowPlayingArtwork::resolve($nowPlaying);
        if ($artwork !== null) {
            $data['artwork'] = $artwork;
        }

        $payload = json_encode($data);

        // Both this listener and a state transition (SyncNowPlayingDataWithState) publish; skip repeats.
        $key = self::publishedKey($deviceId);
        if (Cache::get($key) === md5($payload)) {
            return;
        }
        Cache::put($key, md5($payload), 3600);

        $this->mqttService->publish("remoment/player/{$deviceId}/data", $payload, retain: true);
    }

    /** Zero-length retained payload: "nothing playing", and removes the retained track from the broker. */
    public function clear(int $deviceId): void
    {
        Cache::forget(self::publishedKey($deviceId));

        $this->mqttService->publish("remoment/player/{$deviceId}/data", '', retain: true);
    }

    private static function publishedKey(int $deviceId): string
    {
        return "mqtt_published_data_{$deviceId}";
    }
}
