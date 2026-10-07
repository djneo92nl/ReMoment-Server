<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\RadioStationArtwork;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\MultiRoomPeers;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\SourceSync;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Events\Device\PlaybackModesUpdated;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceDetailResource;
use App\Http\Resources\Api\DeviceListResource;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    use GuardsDeviceAccess;

    public function index(): AnonymousResourceCollection
    {
        return DeviceListResource::collection(SpotifyRouting::visible(Device::all()));
    }

    public function show(Device $device): DeviceDetailResource
    {
        return new DeviceDetailResource($device);
    }

    public function action(Device $device, string $action): JsonResponse
    {
        return $this->withDriver($device, MediaControlsInterface::class, 'media_controls', function (MediaControlsInterface $driver) use ($action) {
            match ($action) {
                'play' => $driver->play(),
                'pause' => $driver->pause(),
                'stop' => $driver->stop(),
                'next' => $driver->next(),
                'previous' => $driver->previous(),
            };

            return ['status' => 'ok', 'action' => $action];
        });
    }

    public function seek(Request $request, Device $device): JsonResponse
    {
        $request->validate(['position' => ['required', 'integer', 'min:0']]);

        return $this->withDriver($device, SeekInterface::class, 'seek', function (SeekInterface $driver) use ($request) {
            $driver->seek($request->integer('position'));

            return ['status' => 'ok', 'position' => $request->integer('position')];
        });
    }

    public function queue(Request $request, Device $device): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->withDriver($device, QueueInterface::class, 'queue', fn (QueueInterface $driver) => [
            'up_next' => array_map(
                fn ($item) => $item->toArray() + ['artwork' => LibraryItemArtwork::forUrl($item->image)],
                $driver->getUpNext($request->integer('limit', 20)),
            ),
        ]);
    }

    /** Plays the queue entry `position` places ahead (1 = the first of `up_next`). */
    public function queueJump(Request $request, Device $device): JsonResponse
    {
        $request->validate(['position' => ['required', 'integer', 'min:1', 'max:100']]);

        return $this->withDriver($device, QueueJumpInterface::class, 'queue_jump', function (QueueJumpInterface $driver) use ($request) {
            $driver->skipToQueuePosition($request->integer('position'));

            return ['status' => 'ok', 'position' => $request->integer('position')];
        });
    }

    public function setShuffle(Request $request, Device $device): JsonResponse
    {
        $request->validate(['shuffle' => ['required', 'boolean']]);
        $shuffle = $request->boolean('shuffle');

        return $this->changeMode($device, ShuffleInterface::class, 'shuffle',
            fn (ShuffleInterface $driver) => $driver->setShuffle($shuffle),
            fn (PlaybackModes $modes) => $modes->withShuffle($shuffle),
            ['shuffle' => $shuffle],
        );
    }

    public function setRepeat(Request $request, Device $device): JsonResponse
    {
        $request->validate(['repeat' => ['required', 'string', Rule::enum(RepeatMode::class)]]);
        $repeat = RepeatMode::from($request->string('repeat')->value());

        return $this->changeMode($device, RepeatInterface::class, 'repeat',
            fn (RepeatInterface $driver) => $driver->setRepeat($repeat),
            fn (PlaybackModes $modes) => $modes->withRepeat($repeat),
            ['repeat' => $repeat->value],
        );
    }

    public function setLike(Request $request, Device $device): JsonResponse
    {
        $request->validate(['liked' => ['required', 'boolean']]);
        $liked = $request->boolean('liked');

        return $this->changeMode($device, LikeInterface::class, 'like',
            fn (LikeInterface $driver) => $driver->setLiked($liked),
            fn (PlaybackModes $modes) => $modes->withLiked($liked),
            ['liked' => $liked],
        );
    }

    public function setPower(Request $request, Device $device): JsonResponse
    {
        $request->validate(['on' => ['required', 'boolean']]);
        $on = $request->boolean('on');

        return $this->withDriver($device, PowerInterface::class, 'power', function (PowerInterface $driver) use ($on) {
            $on ? $driver->powerOn() : $driver->standby();

            return ['on' => $on];
        });
    }

    /**
     * Applies a shuffle/repeat/like change, then records it right away (cache
     * + MQTT `/modes`) instead of waiting for the device listener to see it.
     * On the speaker Spotify is routed to, the Spotify driver applies it.
     */
    private function changeMode(Device $device, string $contract, string $capability, \Closure $apply, \Closure $update, array $response): JsonResponse
    {
        return $this->withDriver($device, $contract, $capability, function ($driver) use ($device, $apply, $update, $response) {
            $apply($driver);

            // While Spotify is routed to a speaker, its modes are reported for the speaker.
            $modesDeviceId = SpotifyRouting::modesDeviceId($device);
            event(new PlaybackModesUpdated((string) $modesDeviceId, $update(Modes::get($modesDeviceId)), routed: SpotifyRouting::routedDeviceId() === $modesDeviceId));

            return $response;
        });
    }

    public function favoriteRadio(Request $request, RadioStation $station): JsonResponse
    {
        $request->validate(['favorite' => ['required', 'boolean']]);

        return response()->json(['favorite' => $station->setFavorite($request->boolean('favorite'))]);
    }

    /** The stations playRadio() accepts for this device, in /radio's order (by name). */
    public function radioStations(Request $request, Device $device): JsonResponse
    {
        $request->validate(['client' => ['nullable', 'string', 'max:100']]);
        $client = $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;

        return $this->withDriver($device, RadioControlInterface::class, 'radio_control', function (RadioControlInterface $driver) use ($client) {
            if (in_array('radio', LibrarySources::hiddenFor($client), true)) {
                return ['stations' => []];
            }

            return ['stations' => RadioStation::query()
                ->with('meta')
                ->orderBy('name')
                ->get()
                ->filter(fn (RadioStation $station) => $driver->canPlayRadioStation($station))
                ->map(fn (RadioStation $station) => [
                    'id' => $station->id,
                    'name' => $station->name,
                    // RadioStation has no genre yet; kept in the shape for clients.
                    'genre' => null,
                    'favorite' => $station->favorited_at !== null,
                    'artwork' => RadioStationArtwork::resolve($station),
                ])
                ->values()];
        });
    }

    public function playRadio(Device $device, RadioStation $station): JsonResponse
    {
        return $this->withDriver($device, RadioControlInterface::class, 'radio_control', function (RadioControlInterface $driver) use ($station) {
            $driver->playRadioStation($station);

            return ['status' => 'ok', 'station' => $station->name];
        });
    }

    public function sources(Device $device): JsonResponse
    {
        return $this->withDriver($device, SourcesInterface::class, 'source_control', function (SourcesInterface $driver) use ($device) {
            $sources = $driver->getSources();
            SourceSync::store($device, $sources);

            return ['sources' => array_map(fn ($s) => $s->toArray(), $sources)];
        }, reachable: false);
    }

    public function activateSource(Request $request, Device $device): JsonResponse
    {
        $request->validate(['source_id' => ['required', 'string']]);

        return $this->withDriver($device, SourceActivationInterface::class, 'source_activation', function (SourceActivationInterface $driver) use ($request) {
            $driver->activateSource($request->string('source_id'));

            return ['status' => 'ok', 'source_id' => $request->string('source_id')];
        }, reachable: false);
    }

    public function getVolume(Device $device): JsonResponse
    {
        return $this->withDriver($device, VolumeControlInterface::class, 'volume_control',
            fn (VolumeControlInterface $driver) => ['volume' => $driver->getVolume()]);
    }

    public function setVolume(Request $request, Device $device): JsonResponse
    {
        $request->validate(['volume' => ['required', 'integer', 'min:0', 'max:100']]);

        return $this->withDriver($device, VolumeControlInterface::class, 'volume_control',
            fn (VolumeControlInterface $driver) => ['volume' => $driver->setVolume($request->integer('volume'))]);
    }

    public function getMute(Device $device): JsonResponse
    {
        return $this->withDriver($device, VolumeControlInterface::class, 'volume_control',
            fn (VolumeControlInterface $driver) => ['muted' => $driver->isMuted()]);
    }

    public function setMute(Request $request, Device $device): JsonResponse
    {
        $request->validate(['muted' => ['required', 'boolean']]);
        $muted = $request->boolean('muted');

        return $this->withDriver($device, VolumeControlInterface::class, 'volume_control', function (VolumeControlInterface $driver) use ($muted) {
            $muted ? $driver->mute() : $driver->unmute();

            return ['muted' => $muted];
        });
    }

    public function multiroom(Device $device): JsonResponse
    {
        return $this->withDriver($device, MultiRoomInterface::class, 'multi_room', function (MultiRoomInterface $driver) use ($device) {
            $peerIds = $driver->getJoinablePeerIds();
            $listenerIds = $driver->getCurrentPeerIds();

            $describe = fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name, 'state' => $d->state?->value];

            return [
                'joinable' => MultiRoomPeers::devices($peerIds, $device)->map($describe)->values(),
                'listeners' => MultiRoomPeers::devices($listenerIds, $device)->map($describe)->values(),
            ];
        }, reachable: false);
    }

    public function multiroomJoin(Request $request, Device $device): JsonResponse
    {
        $request->validate(['host_device_id' => ['required', 'integer', 'exists:devices,id']]);

        $hostDevice = Device::findOrFail($request->integer('host_device_id'));

        return $this->withDriver($device, MultiRoomInterface::class, 'multi_room', function (MultiRoomInterface $driver) use ($hostDevice) {
            $driver->joinSession($hostDevice);

            return ['status' => 'ok', 'joined' => $hostDevice->device_name];
        }, reachable: false);
    }

    public function multiroomLeave(Device $device): JsonResponse
    {
        return $this->withDriver($device, MultiRoomInterface::class, 'multi_room', function (MultiRoomInterface $driver) {
            $driver->leaveSession();

            return ['status' => 'ok'];
        }, reachable: false);
    }

    public function libraryPlay(Request $request, Device $device, LibraryPlayback $library): JsonResponse
    {
        $request->validate(['track_id' => ['required', 'integer', 'exists:tracks,id']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        if (!LibraryPlayback::availableFor($device)) {
            return $this->unsupported('library_playback');
        }

        $track = Track::with('metadata', 'artist', 'album')->findOrFail($request->integer('track_id'));

        return $this->playFromLibrary(fn () => $library->playTrack($device, $track), ['track' => $track->name],
            // Kept for DLNA devices, which answered this before Spotify playback existed.
            LibraryPlayback::canPlayDlna($device) ? ['error' => 'no_dlna_url', 'message' => 'This track has no DLNA stream URL.'] : null,
        );
    }

    /** Plays a library playlist, optionally from a track of it and shuffled; see docs/api/library.md. */
    public function libraryPlayPlaylist(Request $request, Device $device, LibraryPlayback $library): JsonResponse
    {
        $data = $request->validate([
            'playlist_id' => ['required', 'integer', 'exists:playlists,id'],
            'start_track_id' => ['nullable', 'integer', Rule::exists('playlist_track', 'track_id')->where('playlist_id', $request->integer('playlist_id'))],
            'shuffle' => ['nullable', 'boolean'],
        ]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        if (!LibraryPlayback::availableFor($device)) {
            return $this->unsupported('library_playback');
        }

        $playlist = Playlist::findOrFail($data['playlist_id']);
        $start = isset($data['start_track_id']) ? Track::with('metadata')->find($data['start_track_id']) : null;

        return $this->playFromLibrary(fn () => $library->playPlaylist($device, $playlist, $start, $request->boolean('shuffle')), ['playlist' => $playlist->name]);
    }

    /** Plays a library album by DLNA or Spotify; see docs/api/library.md. */
    public function libraryPlayAlbum(Request $request, Device $device, LibraryPlayback $library): JsonResponse
    {
        $data = $request->validate([
            'album_id' => ['required', 'integer', 'exists:albums,id'],
            'start_track_id' => ['nullable', 'integer', Rule::exists('tracks', 'id')->where('album_id', $request->integer('album_id'))],
            'shuffle' => ['nullable', 'boolean'],
        ]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $album = Album::findOrFail($data['album_id']);
        $start = isset($data['start_track_id']) ? Track::find($data['start_track_id']) : null;

        return $this->playFromLibrary(fn () => $library->playAlbum($device, $album, $start, $request->boolean('shuffle')), ['album' => $album->name]);
    }

    /** Plays all library tracks of an artist by DLNA or Spotify; see docs/api/library.md. */
    public function libraryPlayArtist(Request $request, Device $device, LibraryPlayback $library): JsonResponse
    {
        $data = $request->validate([
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'shuffle' => ['nullable', 'boolean'],
        ]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $artist = Artist::findOrFail($data['artist_id']);

        return $this->playFromLibrary(fn () => $library->playArtist($device, $artist, $request->boolean('shuffle')), ['artist' => $artist->name]);
    }

    /** Runs a LibraryPlayback call and maps its outcome to the API's responses. */
    private function playFromLibrary(\Closure $play, array $response, ?array $notPlayable = null): JsonResponse
    {
        try {
            $play();
        } catch (NotPlayableException $e) {
            return response()->json($notPlayable ?? ['error' => 'not_playable', 'message' => $e->getMessage()], 422);
        } catch (PlaybackFailedException $e) {
            return response()->json(['error' => 'driver_error', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['status' => 'ok'] + $response);
    }
}
