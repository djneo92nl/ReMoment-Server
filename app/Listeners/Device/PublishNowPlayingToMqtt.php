<?php

namespace App\Listeners\Device;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Library\Normalizer;
use App\Domain\Media\NowPlaying;
use App\Events\Device\NowPlayingUpdated;
use App\Models\Media\Album;
use App\Models\Media\Artist;
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
            // Clients show "1:35 / 3:40": /progress is only a percentage
            'album' => $nowPlaying->album?->name,
            'duration' => $nowPlaying->track?->duration,
        ];

        // Lets a client open the playing album in its library. Left out when the play
        // history (queued) hasn't created the album yet, or for radio and sources.
        $albumId = $this->libraryAlbumId($nowPlaying);
        if ($albumId !== null) {
            $data['album_id'] = $albumId;
        }

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

    /** Read-only lookup, same keys as LibraryIdentity (artist name_key, then artist + album name_key). */
    private function libraryAlbumId(NowPlaying $nowPlaying): ?int
    {
        $artistName = trim((string) $nowPlaying->track?->artist?->name);
        $albumName = trim((string) $nowPlaying->album?->name);

        if ($artistName === '' || $albumName === '') {
            return null;
        }

        $artistId = Artist::query()->where('name_key', Normalizer::artist($artistName))->orderBy('id')->value('id');
        if ($artistId === null) {
            return null;
        }

        return Album::query()
            ->where('artist_id', $artistId)
            ->where('name_key', Normalizer::album($albumName))
            ->orderBy('id')
            ->value('id');
    }

    private static function publishedKey(int $deviceId): string
    {
        return "mqtt_published_data_{$deviceId}";
    }
}
