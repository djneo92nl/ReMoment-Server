<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use App\Domain\Device\Cache\MultiRoom;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\MultiRoomUpdated;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Integrations\Common\ListenerBackoff;
use App\Integrations\Contracts\DeviceListenerInterface;
use App\Integrations\Contracts\MultiRoomStatusInterface;
use App\Models\Device;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class DeviceListener implements DeviceListenerInterface
{
    protected string $url;

    public static function forDevice(Device $device): ?static
    {
        return new static('http://'.$device->ip_address.':8080/BeoNotify/Notifications');
    }

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
        $backoff = new ListenerBackoff;

        DeviceCache::updateState($deviceId, State::Unreachable);

        while (true) {
            DeviceCache::markListenerAlive($deviceId);

            $hadError = false;

            try {
                $response = $this->http->get($this->url, ['stream' => true]);
                $body = $response->getBody();

                // Connection established — device is reachable. Assume standby until
                // a playing event arrives.
                DeviceCache::updateState($deviceId, State::Standby);

                $buffer = '';
                $lastMultiRoomCheck = 0;
                $this->refreshMultiRoom($deviceId);

                while (!$body->eof()) {
                    $chunk = $body->read(1024);

                    if ($chunk === '') {
                        usleep(100000);

                        // continue;
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
                            $this->applyNotification($decoded, $deviceId);
                        }
                    }

                    DeviceCache::markListenerAlive($deviceId);

                    // Joining or leaving a session doesn't show in the notifications we read: look now and then.
                    if (time() - $lastMultiRoomCheck >= self::MULTIROOM_CHECK_SECONDS) {
                        $lastMultiRoomCheck = time();
                        $this->refreshMultiRoom($deviceId);
                    }
                }
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error("ASE listener [{$deviceId}] error: {$e->getMessage()}", [
                    'exception' => $e,
                    'url' => $this->url,
                ]);
            } finally {
                DeviceCache::forgetListener($deviceId);
                MultiRoom::forget((int) $deviceId);
                DeviceCache::updateState($deviceId, State::Unreachable);
            }

            sleep($backoff->next($hadError));
        }

    }

    private const MULTIROOM_CHECK_SECONDS = 5;

    /** Reads the device's multiroom role and announces it when it changed. */
    protected function refreshMultiRoom(string $deviceId): void
    {
        try {
            $driver = Device::find($deviceId)?->driver;
            if (!($driver instanceof MultiRoomStatusInterface)) {
                return;
            }

            $status = $driver->getMultiRoomStatus();
            if (MultiRoom::get((int) $deviceId)?->toArray() !== $status->toArray()) {
                event(new MultiRoomUpdated($deviceId, $status));
            }
        } catch (\Throwable $e) {
            Log::debug("ASE listener [{$deviceId}] multiroom status failed: {$e->getMessage()}");
        }
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
                    timestamp: isset($n['timestamp']) ? (int) $n['timestamp'] : null,
                ));
                break;

            case 'NOW_PLAYING_STORED_MUSIC':
                event(new NowPlayingUpdated(
                    deviceId: $deviceId,
                    nowPlaying: $this->parseStoredMusic($data),
                    sourceType: 'music',
                ));
                break;
            case 'NOW_PLAYING_ENDED':
                DeviceCache::updateState($deviceId, State::Standby);
                event(new NowPlayingEnded(deviceId: $deviceId));

                break;
            case 'VOLUME':
                event(new VolumeUpdated(
                    deviceId: $deviceId,
                    volume: $data['speaker']['level'],
                    muted: isset($data['speaker']['muted']) ? (bool) $data['speaker']['muted'] : null,
                ));
                break;
            case 'SOURCE':
                if ($data === []) {
                    event(new NowPlayingEnded(deviceId: $deviceId));
                } else {
                    event(new NowPlayingUpdated(
                        deviceId: $deviceId,
                        nowPlaying: $this->parseSource($data),
                        sourceType: 'media',
                    ));
                }
                break;
            case 'PROGRESS_INFORMATION':
                if (($data['state'] ?? '') === 'stop') {
                    break;
                }
                event(new ProgressUpdated(deviceId: $deviceId, progress: $data['position'] ?? 0));
                break;
        }
    }

    public function parseNetRadio(array $payload): NowPlaying
    {
        $radio = new Radio(
            name: $payload['name'],
            images: $payload['image'],
            id: $payload['stationId'] ?? $payload['id'] ?? null,
        );

        if (str_contains($payload['liveDescription'], ' - ')) {
            $artist = new ArtistData(name: explode(' - ', $payload['liveDescription'])[0]);

            $track = new TrackData(
                name: explode(' - ', $payload['liveDescription'])[1],
                artist: $artist,
                images: $payload['image']
            );

            $nowPlaying = new NowPlaying(
                track: $track,
                type: 'music',
                platform: 'radio',
                radio: $radio,
            );
        } else {
            $nowPlaying = new NowPlaying(
                track: new TrackData(
                    name: $payload['liveDescription'], images: $payload['image']
                ),
                radio: $radio
            );
        }

        return $nowPlaying;

    }

    public function parseStoredMusic(array $payload): NowPlaying
    {
        $artist = new ArtistData(name: $payload['artist']);
        $album = new AlbumData(name: $payload['album'], images: $payload['albumImage'], artist: $artist);
        $source = null;
        $meta = [];
        $trackId = isset($payload['trackId']) ? urldecode($payload['trackId']) : null;
        if ($trackId && str_contains($trackId, 'spotify:')) {
            $source = 'spotify';
            $meta['spotifyId'] = $trackId;
        }

        if (isset($payload['sampleRate'])) {
            $meta['sampleRate'] = $payload['sampleRate'];
        }

        if (isset($payload['bitDepth'])) {
            $meta['bitDepth'] = $payload['bitDepth'];
        }

        $track = new TrackData(
            name: $payload['name'],
            source: $source,
            artist: $artist,
            duration: $payload['duration'],
            images: $payload['trackImage'],
            meta: $meta
        );

        $nowPlaying = new NowPlaying(
            track: $track,
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
