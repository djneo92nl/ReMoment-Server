<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\BatteryStatus;
use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Integrations\Contracts\BatteryInterface;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\SoundAdjustmentInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Integrations\Sonos\Connectors\MultiRoomControls;
use App\Integrations\Sonos\Connectors\SettingsControls;
use App\Integrations\Sonos\Connectors\SourceControls;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use duncan3dc\Sonos\Controller;
use duncan3dc\Sonos\Devices\Collection;
use duncan3dc\Sonos\Network;
use duncan3dc\Sonos\Tracks\Stream;
use duncan3dc\Sonos\Tracks\Track as SonosTrack;
use duncan3dc\Sonos\Utils\Time;
use Illuminate\Support\Collection as TrackCollection;

class MusicPlayerDriver implements BatteryInterface, DeviceInfoInterface, LibraryPlaybackInterface, MediaControlsInterface, MultiRoomInterface, MusicPlayerDriverInterface, QueueInterface, QueueJumpInterface, RadioControlInterface, RepeatInterface, SeekInterface, ShuffleInterface, SoundAdjustmentInterface, SourceActivationInterface, SourcesInterface, VolumeControlInterface
{
    use MultiRoomControls;
    use SettingsControls;
    use SourceControls;

    private ?Controller $deviceApi = null;

    public function __construct(public Device $device) {}

    /**
     * Connecting asks the speaker over the network, so it happens on first use:
     * building the driver (capability checks, device lists) must not wait for Sonos.
     */
    public function deviceApiClient(): Controller
    {
        if ($this->deviceApi === null) {
            $collection = (new Collection)->addIp($this->device->ip_address);
            $this->deviceApi = (new Network($collection))->getControllerByIp($this->device->ip_address);
        }

        return $this->deviceApi;
    }

    public function getBattery(): ?BatteryStatus
    {
        return SonosBattery::fetch($this->device->ip_address);
    }

    public function getCurrentPlayingAttribute(): array {}

    public function radioPlatform(): string
    {
        return 'tunein';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->getMeta('tunein') !== null;
    }

    public function playRadioStation(RadioStation $station): void
    {
        $id = $station->getMeta('tunein');
        $uri = "x-sonosapi-stream:{$id}?sid=254&flags=8224&sn=0";
        $this->deviceApiClient()->useStream(new Stream($uri, $station->name));
    }

    public function play(): void
    {
        $this->deviceApiClient()->play();
    }

    public function pause(): void
    {
        $this->deviceApiClient()->pause();
    }

    public function next(): void
    {
        $this->deviceApiClient()->next();
    }

    public function previous(): void
    {
        $this->deviceApiClient()->previous();
    }

    public function stop(): void
    {
        $this->deviceApiClient()->pause();
    }

    public function setVolume(int $volume): int
    {
        $this->deviceApiClient()->setVolume($volume);

        return $volume;
    }

    public function getVolume(): int
    {
        return $this->deviceApiClient()->getVolume();
    }

    public function incrementVolume(): void
    {
        $this->deviceApiClient()->adjustVolume(1);
    }

    public function decrementVolume(): void
    {
        $this->deviceApiClient()->adjustVolume(-1);
    }

    public function mute(): void
    {
        $this->deviceApiClient()->mute(true);
    }

    public function unmute(): void
    {
        $this->deviceApiClient()->unmute();
    }

    public function isMuted(): bool
    {
        return $this->deviceApiClient()->isMuted();
    }

    public function seek(int $seconds): void
    {
        $this->deviceApiClient()->seek(Time::inSeconds(max(0, $seconds)));
    }

    public function setShuffle(bool $shuffle): void
    {
        [, $repeat] = $this->getPlayMode();
        $this->setPlayMode($shuffle, $repeat ?? RepeatMode::Off);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        [$shuffle] = $this->getPlayMode();
        $this->setPlayMode($shuffle ?? false, $mode);
    }

    /** @return array{0: ?bool, 1: ?RepeatMode} */
    public function getPlayMode(): array
    {
        $settings = $this->deviceApiClient()->soap('AVTransport', 'GetTransportSettings')->getArray();

        return PlayMode::parse((string) ($settings['PlayMode'] ?? ''));
    }

    private function setPlayMode(bool $shuffle, RepeatMode $repeat): void
    {
        $this->deviceApiClient()->soap('AVTransport', 'SetPlayMode', [
            'NewPlayMode' => PlayMode::build($shuffle, $repeat),
        ]);
    }

    public function getUpNext(int $limit = 20): array
    {
        $state = $this->deviceApiClient()->getStateDetails();

        // Radio and line-in play a single stream, not the queue.
        if ($state->isStreaming()) {
            return [];
        }

        $tracks = $this->deviceApiClient()->getQueue()->getTracks($state->getNumber() + 1, $limit);

        return array_map(fn ($track) => new QueueItem(
            name: $track->getTitle() ?: 'Unknown',
            artist: $track->getArtist() ?: null,
            album: $track->getAlbum() ?: null,
            image: $track->getAlbumArt() ?: null,
            uri: $track->getUri() ?: null,
        ), $tracks);
    }

    public function skipToQueuePosition(int $position): void
    {
        $api = $this->deviceApiClient();

        // getStateDetails() numbers the playing track from zero, as selectTrack() does
        $api->selectTrack($api->getStateDetails()->getNumber() + max(1, $position));
        $api->play();
    }

    public function playLibraryTrack(Track $track): void
    {
        $url = $track->getDlnaUrl();

        if (!$url) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        $sonosTrack = (new SonosTrack($url))
            ->setTitle($track->name)
            ->setArtist($track->artist?->name ?? '')
            ->setAlbum($track->album?->name ?? '');

        $this->deviceApiClient()->useQueue()->getQueue()->clear()->addTrack($sonosTrack);
        $this->deviceApiClient()->play();
    }

    public function playLibraryTracks(TrackCollection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        $queue = $this->deviceApiClient()->useQueue()->getQueue()->clear();

        foreach ($playable as $track) {
            $queue->addTrack(
                (new SonosTrack($track->getDlnaUrl()))
                    ->setTitle($track->name)
                    ->setArtist($track->artist?->name ?? '')
                    ->setAlbum($track->album?->name ?? '')
            );
        }

        $this->deviceApiClient()->play();
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $tracks = $playlist->tracks()->get();

        if ($tracks->isEmpty()) {
            throw new \RuntimeException("Playlist {$playlist->id} has no tracks.");
        }

        $this->playLibraryTracks($tracks);
    }
}
