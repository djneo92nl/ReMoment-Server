<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\Album;
use App\Domain\Media\Artist;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\Track;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\VolumeUpdated;
use GuzzleHttp\Client;

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
        cache()->put($cacheKey, true, now()->addSeconds(10));

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
                    $parsed = $this->applyNotification($decoded, $deviceId);
                }
            }

            cache()->put($cacheKey, true, now()->addSeconds(10));
        }

        cache()->forget($cacheKey);
        DeviceCache::updateState($deviceId, State::Unreachable);
        $this->listen($deviceId);

    }

    protected function applyNotification(array $payload, string $deviceId): void
    {
        $n = $payload['notification'];
        $data = $n['data'] ?? [];

        switch ($n['type']) {
            case 'NOW_PLAYING_NET_RADIO':
                event(new NowPlayingUpdated(
                    deviceId: $deviceId,
                    nowPlaying: $this->parseNetRadio($data),
                    sourceType: 'radio',
                    timestamp: $n['timestamp'] ?? null
                ));
                break;

            case 'NOW_PLAYING_STORED_MUSIC':
                event(new NowPlayingUpdated(
                    deviceId: $deviceId,
                    nowPlaying: $this->parseStoredMusic($data),
                    sourceType: 'media',
                    timestamp: $n['timestamp'] ?? null
                ));
                break;
            case 'NOW_PLAYING_ENDED':
                event(new NowPlayingEnded(deviceId: $deviceId));
                break;
            case 'VOLUME':
                event(new VolumeUpdated(deviceId: $deviceId, data: $data));
                break;
            case 'SOURCE':
                if ($data === []) {
                    event(new NowPlayingEnded(deviceId: $deviceId));
                } else {
                    $dataParsed = $this->parseSource($data);
                    event(new NowPlayingUpdated(
                        deviceId: $deviceId,
                        nowPlaying: $this->parseStoredMusic($data),
                        sourceType: 'media',
                        timestamp: $n['timestamp'] ?? null
                    ));
                    $type = 'now_playing';
                }
                break;
            case 'PROGRESS_INFORMATION':
                $currentPlaying['data']['position'] = $data['position'];
                $currentPlaying['data']['state'] = $data['state'];
                break;
        }
    }

    public function parseNetRadio(array $payload): NowPlaying
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
                type: 'music',
                platform: 'radio',
                radio: $radio,
            );
        } else {
            $nowPlaying = new NowPlaying(
                track: new Track(
                    name: $payload['liveDescription'], images: $payload['image']
                ),
                radio: $radio
            );
        }

        return $nowPlaying;

    }

    public function parseStoredMusic(array $payload): NowPlaying
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
            album: $album,
            type: 'music',
            platform: 'media',

        );

        return $nowPlaying;
    }

    private function parseSource(mixed $data): NowPlaying
    {
        $source = new Source(
            name: $data['primaryExperience']['source']['friendlyName'],
            category: $data['primaryExperience']['source']['category'],
            jid: $data['primaryExperience']['source']['id'],
            sourceType: $data['primaryExperience']['source']['sourceType']['type'],
            connector: $data['primaryExperience']['source']['sourceType']['connector'] ?? '',
        );

        $nowPlaying = new NowPlaying(
            type: 'video',
            platform: 'media',
            source: $source
        );

        return $nowPlaying;
    }
}
