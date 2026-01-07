<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\Album;
use App\Domain\Media\Artist;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Track;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class DeviceListener
{
    protected string $url;

    protected Client $http;

    public function __construct(string $url)
    {
        $this->url = $url;
        $this->http = new Client([
            'timeout' => 0,        // allow hanging
            'read_timeout' => 0,   // allow streaming
            'stream' => true,
        ]);
    }

    public function listen(string $deviceId)
    {
        $cacheKey = "listener_running_{$deviceId}";
        cache()->put($cacheKey, true, now()->addMinutes(5));

        $response = $this->http->get($this->url, ['stream' => true]);
        $body = $response->getBody();

        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                usleep(100000);

                continue;
            }

            $buffer .= $chunk;

            // Notifications are separated by \r\n\r\n (empty line)
            while (strpos($buffer, "\r\n\r\n") !== false) {
                [$json, $buffer] = explode("\r\n\r\n", $buffer, 2);

                $json = trim($json);

                if ($json === '') {
                    continue;
                }

                $decoded = json_decode($json, true);

                if ($decoded !== null) {
                    $parsed = $this->parseNotification($decoded, $deviceId);
                    cache()->put("device_data_{$deviceId}_{$parsed['type']}", $parsed);
                }
            }

            cache()->put($cacheKey, true, now()->addMinutes(10));
        }

        cache()->forget($cacheKey);
        DeviceCache::updateState($deviceId, State::Unreachable);

    }

    protected function parseNotification(array $payload, string $deviceId): array
    {
        if (!isset($payload['notification'])) {
            return [];
        }

        $n = $payload['notification'];
        $data = $n['data'] ?? [];

        $type = $n['type'];

        dump($type);

        if ($n['type'] === 'NOW_PLAYING_NET_RADIO') {
            $dataParsed = $this->parseNetRadio($data);
            $type = 'now_playing';
        }
        if ($n['type'] === 'NOW_PLAYING_STORED_MUSIC') {
            $dataParsed = $this->parseStoredMusic($data);
            $type = 'now_playing';
        }

        if ($n['type'] === 'PROGRESS_INFORMATION') {
            $currentPlaying = Cache::get('device_data_'.$deviceId.'_now_playing');
            if ($currentPlaying !== null && isset($data['position'])) {
                $currentPlaying['data']['position'] = $data['position'];
                $currentPlaying['data']['state'] = $data['state'];
                $dataParsed = $currentPlaying['data'];
                $type = 'now_playing';

                DeviceCache::updateState($deviceId, State::Playing);

            }
        }

        if ($n['type'] === 'NOW_PLAYING_ENDED') {
            Cache::forget('device_data_'.$deviceId.'_now_playing');
            $dataParsed = ['state' => 'ended'];
            $type = 'now_playing_ended';

            DeviceCache::updateState($deviceId, State::Standby);
        }
        if ($n['type'] === 'VOLUME') {
            Volume::updateVolume($deviceId, $data['speaker']['level']);
        }

        return [
            'id' => $n['id'] ?? null,
            'timestamp' => $n['timestamp'] ?? null,
            'type' => $type ?? null,
            'kind' => $n['kind'] ?? null,
            'data' => $dataParsed ?? [],
        ];
    }

    public function parseNetRadio(array $payload): array
    {
        $radio = new Radio(name: $payload['name'], images: $payload['image']);

        if (str_contains($payload['liveDescription'], ' - ')) {
            $artist = new Artist(name: explode(' - ', $payload['liveDescription'])[0]);

            $track = new Track(
                name: explode(' - ', $payload['liveDescription'])[1],
                artist: $artist,
                images: $payload['image']
            );

            $nowPlaying = new NowPlaying(
                track: $track,
                artist: $artist,
                radio: $radio
            );
        } else {
            $nowPlaying = new NowPlaying(
                track: new Track(
                    name: $payload['liveDescription'], images: $payload['image']
                ),
                radio: $radio
            );
        }

        return $nowPlaying->toArray();

    }

    public function parseStoredMusic(array $payload)
    {

        $artist = new Artist(name: $payload['artist']);
        $album = new Album(name: $payload['album'], images: $payload['albumImage'], artist: $artist);
        $track = new Track(
            name: $payload['name'],
            artist: $artist,
            duration: $payload['duration'],
            images: $payload['trackImage'],
            meta: ['spotifyId' => urldecode($payload['trackId'])]
        );

        $nowPlaying = new NowPlaying(
            track: $track,
            artist: $artist,
            album: $album
        );

        return $nowPlaying->toArray();
    }
}
