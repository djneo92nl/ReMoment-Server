<?php

namespace App\Domain\Playback;

use App\Domain\Artwork\SourceLogo;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\DlnaTrackLookup;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\NowPlayingTrackResolver;
use App\Domain\Media\NowPlaying;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\ReMoment\PlayerDriver;
use App\Integrations\Upnp\MusicPlayerDriver as UpnpDriver;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\RadioStation;

/**
 * Reads what a device plays into a PlaybackSnapshot another device can pick up. Nothing is asked of the
 * device live: the now playing and position are the listener's last report (DeviceCache).
 *
 * - Spotify (routed to the speaker, or the Spotify device itself): no snapshot needed, Spotify moves it.
 * - Radio: the station.
 * - Library tracks: the whole queue. A device with a server-owned queue (UPnP, ReMoment) has it in
 *   DeviceQueue; one with its own (Sonos, ASE) gives the current track by identity and the rest by
 *   `getUpNext()`, each mapped back to the library; entries that don't map are dropped.
 * - Anything else, a local input (line-in, TV, CD, Bluetooth, AirPlay/Cast) or nothing: NotMovableException.
 */
final class PlaybackCapture
{
    /** Source kinds (SourceLogo keys) that only exist on the device itself. */
    private const LOCAL_INPUTS = ['line_in', 'cd', 'tv', 'bluetooth', 'cast'];

    public function capture(Device $device): PlaybackSnapshot
    {
        $nowPlaying = DeviceCache::getNowPlaying($device->id);
        $state = DeviceCache::getState($device->id);

        if ($nowPlaying === null || !in_array($state, [State::Playing, State::Paused], true)) {
            throw new NotMovableException("{$device->device_name} isn't playing anything.");
        }

        $paused = $state === State::Paused;

        if (self::isSpotify($device, $nowPlaying)) {
            return new PlaybackSnapshot(PlaybackSnapshot::SPOTIFY, $nowPlaying->position, $paused);
        }

        if ($nowPlaying->radio !== null) {
            $station = $this->station($device, $nowPlaying)
                ?? throw new NotMovableException('This radio stream is not one of the stations, so it cannot be started elsewhere.');

            return new PlaybackSnapshot(PlaybackSnapshot::RADIO, 0, $paused, station: $station);
        }

        if (self::isLocalInput($nowPlaying)) {
            $name = $nowPlaying->source?->name ?? 'This source';

            throw new NotMovableException("{$name} is an input of {$device->device_name} itself and can't be moved to another device.");
        }

        $trackIds = $this->libraryQueue($device, $nowPlaying);

        if ($trackIds === []) {
            throw new NotMovableException('What is playing is not in the library, so another device cannot play it.');
        }

        return new PlaybackSnapshot(PlaybackSnapshot::LIBRARY, $nowPlaying->position, $paused, $trackIds);
    }

    /**
     * Whether something movable plays on $device, from the cache alone (no call to the device, no library
     * lookup), so it is cheap enough for a device list. `capture()` can still refuse: a radio stream that is no
     * station, a track the library doesn't have.
     */
    public function movable(Device $device): bool
    {
        $nowPlaying = DeviceCache::getNowPlaying($device->id);

        if ($nowPlaying === null || !in_array(DeviceCache::getState($device->id), [State::Playing, State::Paused], true)) {
            return false;
        }

        return self::isSpotify($device, $nowPlaying) || $nowPlaying->radio !== null || (!self::isLocalInput($nowPlaying) && $nowPlaying->track !== null);
    }

    /** Whether Spotify's own transfer applies: Spotify is routed to this speaker, or it is the Spotify device. */
    public static function isSpotify(Device $device, NowPlaying $nowPlaying): bool
    {
        if (SpotifyRouting::isRoutedTarget($device)) {
            return true;
        }

        return SpotifyRouting::isSpotify($device) && $nowPlaying->track !== null;
    }

    /** An input of the device (no track, or a source that only its own hardware outputs). */
    public static function isLocalInput(NowPlaying $nowPlaying): bool
    {
        if ($nowPlaying->source?->playsLocally === true) {
            return true;
        }

        if ($nowPlaying->track === null) {
            return $nowPlaying->source !== null;
        }

        return in_array(SourceLogo::keyFor($nowPlaying), self::LOCAL_INPUTS, true);
    }

    /** @return int[] track ids in play order, the current track first; [] when it can't be mapped */
    private function libraryQueue(Device $device, NowPlaying $nowPlaying): array
    {
        if (is_a((string) $device->device_driver, UpnpDriver::class, true) || is_a((string) $device->device_driver, PlayerDriver::class, true)) {
            $queue = DeviceQueue::for($device->id);

            if (($current = $queue->current()) !== null) {
                return array_merge([$current], $queue->upNext(PHP_INT_MAX));
            }
        }

        $current = $this->currentTrack($nowPlaying);

        if ($current === null) {
            return [];
        }

        $ids = [$current->id];

        if ($device->driver instanceof QueueInterface) {
            foreach ($device->driver->getUpNext(100) as $item) {
                $track = $item->uri ? DlnaTrackLookup::byUrl($item->uri) : null;

                if ($track !== null) {
                    $ids[] = $track->id;
                }
            }
        }

        return $ids;
    }

    private function currentTrack(NowPlaying $nowPlaying): ?Track
    {
        $track = $nowPlaying->track;

        if ($track === null || trim((string) $track->name) === '') {
            return null;
        }

        return LibraryIdentity::lookupTrack(
            trim((string) $track->artist?->name),
            $nowPlaying->album?->name,
            trim((string) $track->name),
            NowPlayingTrackResolver::externalId($nowPlaying),
            $track->source,
            $track->duration,
        );
    }

    private function station(Device $device, NowPlaying $nowPlaying): ?RadioStation
    {
        $id = UpnpDriver::radio($device->id)['station_id'] ?? PlayerDriver::stream($device->id)['station_id'] ?? null;

        if ($id !== null && ($station = RadioStation::find($id))) {
            return $station;
        }

        return $nowPlaying->radio?->name ? RadioStation::where('name', $nowPlaying->radio->name)->first() : null;
    }
}
