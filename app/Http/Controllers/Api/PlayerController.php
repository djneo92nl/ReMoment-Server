<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\TrackData;
use App\Domain\Radio\IcyReader;
use App\Domain\Radio\StreamTitles;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Http\Controllers\Controller;
use App\Integrations\ReMoment\PlayerCommands;
use App\Integrations\ReMoment\PlayerDevice;
use App\Integrations\ReMoment\PlayerDriver;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The player side of the ReMoment Player protocol; see docs/api/players.md. */
class PlayerController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:100'],
            'adopt_device_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:'.implode(',', PlayerDevice::KINDS)],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string'],
            'formats' => ['nullable', 'array'],
            'formats.*' => ['string', 'max:100'],
            'profile' => ['nullable', 'string', 'max:50'],
        ]);

        [$device, $token] = PlayerDevice::register($data['token'] ?? null, $data['name'], $data['kind'], $data['capabilities'] ?? null, $data['formats'] ?? null, $data['adopt_device_id'] ?? null, $data['profile'] ?? null);

        // A player that registers is online until it misses its heartbeats.
        DeviceCache::markListenerAlive($device->id);
        DeviceCache::updateState($device->id, DeviceCache::getState($device->id) ?? State::Standby);

        return response()->json([
            'player_token' => $token,
            'device_id' => $device->id,
            'name' => $device->device_name,
            'command_topic' => PlayerCommands::topic($device->id),
            'last_command_id' => PlayerCommands::lastId($device->id),
            'mqtt' => [
                'host' => config('mqtt.public_host') ?? $request->getHost(),
                'port' => (int) config('mqtt.public_port'),
                'ws_port' => (int) config('mqtt.public_ws_port'),
            ],
        ], 201);
    }

    /** The existing players, so a new browser can take one over instead of adding another. */
    public function index(): JsonResponse
    {
        $players = Device::where('device_driver', PlayerDriver::class)->orderBy('device_name')->get();

        return response()->json(['data' => $players->map(fn (Device $device) => [
            'id' => $device->id,
            'name' => $device->device_name,
            'kind' => PlayerDevice::kind($device),
            'state' => $device->liveState()->value,
        ])->values()]);
    }

    public function state(Request $request, string $token): JsonResponse
    {
        $device = $this->device($token);

        $data = $request->validate([
            'state' => ['required', 'in:playing,paused,stopped'],
            'track_id' => ['nullable', 'integer'],
            'position' => ['nullable', 'numeric', 'min:0'],
            'duration' => ['nullable', 'numeric', 'min:0'],
            'volume' => ['nullable', 'integer', 'between:0,100'],
            'muted' => ['nullable', 'boolean'],
            'ended' => ['nullable', 'boolean'],
        ]);

        DeviceCache::markListenerAlive($device->id);
        $id = (string) $device->id;

        if (isset($data['volume'])) {
            $before = Volume::getVolume($device->id);
            if ($before !== $data['volume'] || ($data['muted'] ?? null) !== Volume::getMuted($device->id)) {
                event(new VolumeUpdated($id, $data['volume'], $data['muted'] ?? null));
            }
        }

        $stream = PlayerDriver::stream($device->id);

        // A live stream that "ends" has dropped: there is no next track, so the player just stops.
        if ($stream && (($data['ended'] ?? false) || $data['state'] === 'stopped')) {
            PlayerDriver::forgetStream($device->id);
            DeviceCache::updateState($device->id, State::Standby);
            if (DeviceCache::getNowPlaying($device->id)) {
                event(new NowPlayingEnded($id));
            }

            return response()->json(['status' => 'ok']);
        }

        if ($data['ended'] ?? false) {
            $device->driver->trackEnded();

            return response()->json(['status' => 'ok']);
        }

        if ($data['state'] === 'stopped') {
            DeviceCache::updateState($device->id, State::Standby);
            if (DeviceCache::getNowPlaying($device->id)) {
                event(new NowPlayingEnded($id));
            }

            return response()->json(['status' => 'ok']);
        }

        DeviceCache::updateState($device->id, $data['state'] === 'playing' ? State::Playing : State::Paused);

        if ($stream) {
            $this->reportStation($id, $stream);

            return response()->json(['status' => 'ok']);
        }

        $track = isset($data['track_id']) ? Track::with(['artist', 'album'])->find($data['track_id']) : null;

        if ($track && (string) DeviceCache::getNowPlaying($device->id)?->track?->id !== (string) $track->id) {
            event(new NowPlayingUpdated(deviceId: $id, nowPlaying: $this->nowPlaying($track, (int) ($data['position'] ?? 0), $data['state']), sourceType: 'local'));
        }

        if (isset($data['position'])) {
            if ($data['state'] === 'playing') {
                event(new ProgressUpdated(deviceId: $id, progress: (int) round($data['position'])));
            } elseif ($cached = DeviceCache::getNowPlaying($device->id)) {
                // A paused player still says where it stands, but a progress event means "playing" to the cache
                // (UpdateDeviceCache), so it would turn the pause just stored above back into a playing device.
                $cached->position = (int) round($data['position']);
                DeviceCache::updateNowPlaying($device->id, $cached);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /** Commands after `since` (an id); waits up to `wait` seconds (max 25) for one when there are none. */
    public function commands(Request $request, string $token): JsonResponse
    {
        $device = $this->device($token);
        $since = (int) $request->query('since', 0);
        $deadline = microtime(true) + min(25, max(0, (int) $request->query('wait', 0)));

        DeviceCache::markListenerAlive($device->id);

        do {
            $commands = PlayerCommands::since($device->id, $since);
            if ($commands || microtime(true) >= $deadline) {
                break;
            }
            usleep(500_000);
        } while (true);

        return response()->json(['commands' => $commands]);
    }

    private function device(string $token): Device
    {
        return PlayerDevice::findByToken($token) ?? abort(404, 'Unknown player.');
    }

    private function nowPlaying(Track $track, int $position, string $state): NowPlaying
    {
        $artist = $track->artist ? new ArtistData(name: $track->artist->name) : null;
        $images = array_values(array_filter([PlayerDevice::cover($track)]));
        $album = $track->album ? new AlbumData(name: $track->album->name, images: $images, artist: $artist) : null;

        return new NowPlaying(
            track: new TrackData(id: (string) $track->id, name: $track->name, source: 'local', artist: $artist, duration: $track->duration, images: $images),
            album: $album,
            state: $state,
            position: $position,
            type: 'music',
            platform: 'media',
        );
    }

    /**
     * A station's now playing: the station is the radio, the stream's ICY title the song (kept fresh by StreamTitles).
     * Published when it changes. Other live streams (the Spotify stream) report their own now playing.
     *
     * @param  array{station_id: int|null}  $stream
     */
    private function reportStation(string $id, array $stream): void
    {
        $station = $stream['station_id'] ? RadioStation::with('meta')->find($stream['station_id']) : null;

        if (!$station) {
            return;
        }

        StreamTitles::touch($station);

        $images = array_values(array_filter([$stream['art'] ?? $station->image_url]));
        $title = StreamTitles::get($station->id);
        [$artistName, $song] = $title !== null ? IcyReader::split($title) : [null, $station->name];

        $cached = DeviceCache::getNowPlaying((int) $id);
        if ($cached?->radio?->name === $station->name && $cached->track?->name === $song && $cached->track?->artist?->name === $artistName) {
            return;
        }

        event(new NowPlayingUpdated(deviceId: $id, nowPlaying: new NowPlaying(
            track: new TrackData(name: $song, artist: $artistName !== null ? new ArtistData(name: $artistName) : null, images: $images),
            position: 0,
            type: 'music',
            platform: 'radio',
            radio: new Radio(name: $station->name, genre: $station->genre, images: $images, id: $station->streamUrl()),
        ), sourceType: 'radio'));
    }
}
