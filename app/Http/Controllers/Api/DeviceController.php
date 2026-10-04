<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\RadioStationArtwork;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Events\Device\PlaybackModesUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceDetailResource;
use App\Http\Resources\Api\DeviceListResource;
use App\Integrations\Common\UnsupportedOperationException;
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
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = SpotifyRouting::driverFor($device, MediaControlsInterface::class);

        if (!($driver instanceof MediaControlsInterface)) {
            return $this->unsupported('media_controls');
        }

        try {
            match ($action) {
                'play' => $driver->play(),
                'pause' => $driver->pause(),
                'stop' => $driver->stop(),
                'next' => $driver->next(),
                'previous' => $driver->previous(),
            };
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'action' => $action]);
    }

    public function seek(Request $request, Device $device): JsonResponse
    {
        $request->validate(['position' => ['required', 'integer', 'min:0']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = SpotifyRouting::driverFor($device, SeekInterface::class);

        if (!($driver instanceof SeekInterface)) {
            return $this->unsupported('seek');
        }

        try {
            $driver->seek($request->integer('position'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'position' => $request->integer('position')]);
    }

    public function queue(Request $request, Device $device): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = SpotifyRouting::driverFor($device, QueueInterface::class);

        if (!($driver instanceof QueueInterface)) {
            return $this->unsupported('queue');
        }

        try {
            $items = $driver->getUpNext($request->integer('limit', 20));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['up_next' => array_map(fn ($item) => $item->toArray() + ['artwork' => LibraryItemArtwork::forUrl($item->image)], $items)]);
    }

    /** Plays the queue entry `position` places ahead (1 = the first of `up_next`). */
    public function queueJump(Request $request, Device $device): JsonResponse
    {
        $request->validate(['position' => ['required', 'integer', 'min:1', 'max:100']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = SpotifyRouting::driverFor($device, QueueJumpInterface::class);

        if (!($driver instanceof QueueJumpInterface)) {
            return $this->unsupported('queue_jump');
        }

        try {
            $driver->skipToQueuePosition($request->integer('position'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'position' => $request->integer('position')]);
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

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof PowerInterface)) {
            return $this->unsupported('power');
        }

        try {
            $on ? $driver->powerOn() : $driver->standby();
        } catch (UnsupportedOperationException $e) {
            return response()->json(['error' => 'unsupported', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['on' => $on]);
    }

    /**
     * Applies a shuffle/repeat/like change, then records it right away (cache
     * + MQTT `/modes`) instead of waiting for the device listener to see it.
     * On the speaker Spotify is routed to, the Spotify driver applies it.
     */
    private function changeMode(Device $device, string $contract, string $capability, \Closure $apply, \Closure $update, array $response): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = SpotifyRouting::driverFor($device, $contract);

        if (!($driver instanceof $contract)) {
            return $this->unsupported($capability);
        }

        try {
            $apply($driver);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        // While Spotify is routed to a speaker, its modes are reported for the speaker.
        $modesDeviceId = SpotifyRouting::modesDeviceId($device);
        event(new PlaybackModesUpdated((string) $modesDeviceId, $update(Modes::get($modesDeviceId)), routed: SpotifyRouting::routedDeviceId() === $modesDeviceId));

        return response()->json($response);
    }

    /** The stations playRadio() accepts for this device, in /radio's order (by name). */
    public function radioStations(Request $request, Device $device): JsonResponse
    {
        $request->validate(['client' => ['nullable', 'string', 'max:100']]);
        $client = $request->filled('client') ? Client::where('api_token', $request->query('client'))->firstOrFail() : null;

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof RadioControlInterface)) {
            return $this->unsupported('radio_control');
        }

        if (in_array('radio', LibrarySources::hiddenFor($client), true)) {
            return response()->json(['stations' => []]);
        }

        $stations = RadioStation::query()
            ->with('meta')
            ->orderBy('name')
            ->get()
            ->filter(fn (RadioStation $station) => $driver->canPlayRadioStation($station))
            ->map(fn (RadioStation $station) => [
                'id' => $station->id,
                'name' => $station->name,
                // RadioStation has no genre yet; kept in the shape for clients.
                'genre' => null,
                'artwork' => RadioStationArtwork::resolve($station),
            ])
            ->values();

        return response()->json(['stations' => $stations]);
    }

    public function playRadio(Device $device, RadioStation $station): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof RadioControlInterface)) {
            return $this->unsupported('radio_control');
        }

        try {
            $driver->playRadioStation($station);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'station' => $station->name]);
    }

    public function sources(Device $device): JsonResponse
    {
        $driver = $device->driver;

        if (!($driver instanceof SourcesInterface)) {
            return $this->unsupported('source_control');
        }

        try {
            $sources = $driver->getSources();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        $device->deviceSources()->delete();
        $device->deviceSources()->createMany(
            array_map(fn ($s) => [
                'source_id' => $s->sourceId,
                'friendly_name' => $s->friendlyName,
                'source_type' => $s->sourceType,
                'category' => $s->category,
                'in_use' => $s->inUse,
                'borrowed' => $s->borrowed,
                'provider_jid' => $s->providerJid,
                'provider_name' => $s->providerName,
            ], $sources)
        );

        return response()->json(['sources' => array_map(fn ($s) => $s->toArray(), $sources)]);
    }

    public function activateSource(Request $request, Device $device): JsonResponse
    {
        $request->validate(['source_id' => ['required', 'string']]);

        $driver = $device->driver;

        if (!($driver instanceof SourceActivationInterface)) {
            return $this->unsupported('source_activation');
        }

        try {
            $driver->activateSource($request->string('source_id'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'source_id' => $request->string('source_id')]);
    }

    public function getVolume(Device $device): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return $this->unsupported('volume_control');
        }

        return response()->json(['volume' => $driver->getVolume()]);
    }

    public function setVolume(Request $request, Device $device): JsonResponse
    {
        $request->validate(['volume' => ['required', 'integer', 'min:0', 'max:100']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return $this->unsupported('volume_control');
        }

        try {
            $actual = $driver->setVolume($request->integer('volume'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['volume' => $actual]);
    }

    public function getMute(Device $device): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return $this->unsupported('volume_control');
        }

        try {
            $muted = $driver->isMuted();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['muted' => $muted]);
    }

    public function setMute(Request $request, Device $device): JsonResponse
    {
        $request->validate(['muted' => ['required', 'boolean']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return $this->unsupported('volume_control');
        }

        try {
            $request->boolean('muted') ? $driver->mute() : $driver->unmute();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['muted' => $request->boolean('muted')]);
    }

    public function multiroom(Device $device): JsonResponse
    {
        $driver = $device->driver;

        if (!($driver instanceof MultiRoomInterface)) {
            return $this->unsupported('multi_room');
        }

        try {
            $peerIds = $driver->getJoinablePeerIds();
            $listenerIds = $driver->getCurrentPeerIds();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        $joinable = $this->mapPeerIdsToDevices($peerIds, $device);
        $listeners = $this->mapPeerIdsToDevices($listenerIds, $device);

        return response()->json([
            'joinable' => $joinable->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name, 'state' => $d->state?->value])->values(),
            'listeners' => $listeners->map(fn ($d) => ['id' => $d->id, 'device_name' => $d->device_name, 'state' => $d->state?->value])->values(),
        ]);
    }

    public function multiroomJoin(Request $request, Device $device): JsonResponse
    {
        $request->validate(['host_device_id' => ['required', 'integer', 'exists:devices,id']]);

        $driver = $device->driver;

        if (!($driver instanceof MultiRoomInterface)) {
            return $this->unsupported('multi_room');
        }

        $hostDevice = Device::findOrFail($request->integer('host_device_id'));

        try {
            $driver->joinSession($hostDevice);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'joined' => $hostDevice->device_name]);
    }

    public function multiroomLeave(Device $device): JsonResponse
    {
        $driver = $device->driver;

        if (!($driver instanceof MultiRoomInterface)) {
            return $this->unsupported('multi_room');
        }

        try {
            $driver->leaveSession();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok']);
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

    private function mapPeerIdsToDevices(array $ids, Device $exclude): \Illuminate\Support\Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $driver = $exclude->driver;
        if (!($driver instanceof MultiRoomInterface)) {
            return collect();
        }

        $metaKey = $driver->multiRoomMetaKey();

        return Device::whereHas('meta', function ($q) use ($ids, $metaKey) {
            $q->where('key', $metaKey)->whereIn('value', $ids);
        })->where('id', '!=', $exclude->id)->get();
    }

    private function assertReachable(Device $device): ?JsonResponse
    {
        if ($device->state === State::Unreachable) {
            return response()->json([
                'error' => 'unreachable',
                'message' => 'Device is not reachable.',
            ], 503);
        }

        return null;
    }

    private function unsupported(string $capability): JsonResponse
    {
        return response()->json([
            'error' => 'unsupported',
            'message' => "This device does not support {$capability}.",
        ], 422);
    }
}
