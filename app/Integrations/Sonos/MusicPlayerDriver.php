<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Integrations\Sonos\Connectors\MultiRoomControls;
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

class MusicPlayerDriver implements LibraryPlaybackInterface, MediaControlsInterface, MultiRoomInterface, MusicPlayerDriverInterface, QueueInterface, RadioControlInterface, RepeatInterface, SeekInterface, ShuffleInterface, VolumeControlInterface
{
    use MultiRoomControls;

    public Controller $deviceApi;

    public function __construct(public Device $device)
    {
        $collection = (new Collection)->addIp($device->ip_address);
        $sonos = new Network($collection);
        $this->deviceApi = $sonos->getControllerByIp($device->ip_address);
    }

    public function deviceApiClient(): Controller
    {
        return $this->deviceApi;
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
        $this->deviceApi->useStream(new Stream($uri, $station->name));
    }

    public function play(): void
    {
        $this->deviceApi->play();
    }

    public function pause(): void
    {
        $this->deviceApi->pause();
    }

    public function next(): void
    {
        $this->deviceApi->next();
    }

    public function previous(): void
    {
        $this->deviceApi->previous();
    }

    public function stop(): void
    {
        $this->deviceApi->pause();
    }

    public function setVolume(int $volume): int
    {
        $this->deviceApi->setVolume($volume);

        return $volume;
    }

    public function getVolume(): int
    {
        return $this->deviceApi->getVolume();
    }

    public function incrementVolume(): void
    {
        $this->deviceApi->adjustVolume(1);
    }

    public function decrementVolume(): void
    {
        $this->deviceApi->adjustVolume(-1);
    }

    public function mute(): void
    {
        $this->deviceApi->mute(true);
    }

    public function unmute(): void
    {
        $this->deviceApi->unmute();
    }

    public function isMuted(): bool
    {
        return $this->deviceApi->isMuted();
    }

    public function seek(int $seconds): void
    {
        $this->deviceApi->seek(Time::inSeconds(max(0, $seconds)));
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
        $settings = $this->deviceApi->soap('AVTransport', 'GetTransportSettings')->getArray();

        return PlayMode::parse((string) ($settings['PlayMode'] ?? ''));
    }

    private function setPlayMode(bool $shuffle, RepeatMode $repeat): void
    {
        $this->deviceApi->soap('AVTransport', 'SetPlayMode', [
            'NewPlayMode' => PlayMode::build($shuffle, $repeat),
        ]);
    }

    public function getUpNext(int $limit = 20): array
    {
        $state = $this->deviceApi->getStateDetails();

        // Radio and line-in play a single stream, not the queue.
        if ($state->isStreaming()) {
            return [];
        }

        $tracks = $this->deviceApi->getQueue()->getTracks($state->getNumber() + 1, $limit);

        return array_map(fn ($track) => new QueueItem(
            name: $track->getTitle() ?: 'Unknown',
            artist: $track->getArtist() ?: null,
            album: $track->getAlbum() ?: null,
            image: $track->getAlbumArt() ?: null,
            uri: $track->getUri() ?: null,
        ), $tracks);
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

        $this->deviceApi->useQueue()->getQueue()->clear()->addTrack($sonosTrack);
        $this->deviceApi->play();
    }

    public function playLibraryTracks(TrackCollection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        $queue = $this->deviceApi->useQueue()->getQueue()->clear();

        foreach ($playable as $track) {
            $queue->addTrack(
                (new SonosTrack($track->getDlnaUrl()))
                    ->setTitle($track->name)
                    ->setArtist($track->artist?->name ?? '')
                    ->setAlbum($track->album?->name ?? '')
            );
        }

        $this->deviceApi->play();
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
