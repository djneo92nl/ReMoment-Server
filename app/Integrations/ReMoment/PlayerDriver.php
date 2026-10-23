<?php

namespace App\Integrations\ReMoment;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Domain\Radio\StreamTitles;
use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\Common\EditsDeviceQueue;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueClearInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\QueueMoveInterface;
use App\Integrations\Contracts\QueuePlayNextInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A ReMoment player (browser tab, app, ESP32) that plays the server's own files. The player does the
 * playing; this driver keeps the queue and turns every control into a command for it (PlayerCommands).
 */
class PlayerDriver implements LibraryPlaybackInterface, MediaControlsInterface, MusicPlayerDriverInterface, PlayStreamUrlInterface, QueueAddInterface, QueueClearInterface, QueueInterface, QueueJumpInterface, QueueMoveInterface, QueuePlayNextInterface, QueueRemoveInterface, RadioControlInterface, RepeatInterface, SeekInterface, ShuffleInterface, VolumeControlInterface
{
    use EditsDeviceQueue;

    public function __construct(public Device $device) {}

    protected function canQueue(Track $track): bool
    {
        return self::playable($track, $this->device);
    }

    protected function queueUri(Track $track): ?string
    {
        return StreamUrl::for($track->id);
    }

    public function play(): void
    {
        $this->send('resume');
    }

    public function pause(): void
    {
        $this->send('pause');
    }

    public function stop(): void
    {
        self::forgetStream($this->device->id);
        $this->send('stop');
    }

    public function radioPlatform(): string
    {
        return 'url';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->streamUrl() !== null;
    }

    /** The browser plays the stream itself; the station and, from the ICY title, the song are the now playing (see PlayerController). */
    public function playRadioStation(RadioStation $station): void
    {
        $url = $station->streamUrl() ?? throw new \App\Integrations\Common\UnsupportedOperationException('This station has no stream URL.');
        $cover = LibraryItemArtwork::proxy($station->image_url, 320);

        $this->startStream($url, $station->name, $cover, $station->id);
        StreamTitles::touch($station);
    }

    public function playStreamUrl(string $url, string $title, ?string $art = null): void
    {
        $this->startStream($url, $title, $art, null);
    }

    /** @return array{url: string, name: string, station_id: int|null}|null what this player plays as a live stream, while it does */
    public static function stream(int $deviceId): ?array
    {
        return Cache::get(self::streamKey($deviceId));
    }

    public static function forgetStream(int $deviceId): void
    {
        Cache::forget(self::streamKey($deviceId));
    }

    private static function streamKey(int $deviceId): string
    {
        return "player:{$deviceId}:stream";
    }

    private function startStream(string $url, string $name, ?string $art, ?int $stationId): void
    {
        Cache::put(self::streamKey($this->device->id), ['url' => $url, 'name' => $name, 'art' => $art, 'station_id' => $stationId], now()->addDay());

        $this->send('play', ['track' => [
            'id' => null,
            'live' => true,
            'url' => $url,
            'profile' => null,
            'mime' => 'audio/mpeg',
            'name' => $name,
            'artist' => null,
            'album' => null,
            'duration' => null,
            'images' => $art ? [$art] : [],
            'lite' => null,
        ]]);
    }

    public function next(): void
    {
        $this->startTrack(fn (DeviceQueue $queue) => $queue->advance(manual: true));
    }

    public function previous(): void
    {
        $this->startTrack(fn (DeviceQueue $queue) => $queue->back());
    }

    public function seek(int $seconds): void
    {
        $this->send('seek', ['position' => max(0, $seconds)]);
    }

    public function skipToQueuePosition(int $position): void
    {
        $this->startTrack(fn (DeviceQueue $queue) => $queue->jump(max(1, $position)));
    }

    public function getUpNext(int $limit = 20): array
    {
        $ids = DeviceQueue::for($this->device->id)->upNext($limit);
        $tracks = Track::with(['artist', 'album'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->filter(fn ($id) => $tracks->has($id))->map(function ($id) use ($tracks) {
            $track = $tracks[$id];

            return new QueueItem(
                name: $track->name,
                artist: $track->artist?->name,
                album: $track->album?->name,
                image: PlayerDevice::cover($track),
                duration: $track->duration,
                uri: StreamUrl::for($track->id),
            );
        })->values()->all();
    }

    public function setShuffle(bool $shuffle): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $queue->setShuffle($shuffle);
        $queue->save($this->device->id);
        $this->reportModes($queue);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $queue->repeat = $mode->value;
        $queue->save($this->device->id);
        $this->reportModes($queue);
    }

    public function setVolume(int $volume): int
    {
        $volume = max(0, min(100, $volume));
        Volume::updateVolume($this->device->id, $volume);
        $this->send('volume', ['volume' => $volume]);

        return $volume;
    }

    public function getVolume(): int
    {
        return Volume::remember($this->device->id, fn () => 100);
    }

    public function incrementVolume(): void
    {
        $this->setVolume($this->getVolume() + 5);
    }

    public function decrementVolume(): void
    {
        $this->setVolume($this->getVolume() - 5);
    }

    public function mute(): void
    {
        Volume::updateMuted($this->device->id, true);
        $this->send('mute', ['muted' => true]);
    }

    public function unmute(): void
    {
        Volume::updateMuted($this->device->id, false);
        $this->send('mute', ['muted' => false]);
    }

    public function isMuted(): bool
    {
        return Volume::getMuted($this->device->id) ?? false;
    }

    public function playLibraryTrack(Track $track): void
    {
        $this->playLibraryTracks(collect([$track]));
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => self::playable($track, $this->device))->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks this player can play.');
        }

        $queue = DeviceQueue::for($this->device->id);
        $queue->replace($playable->pluck('id')->all());
        $queue->save($this->device->id);

        $this->playCurrent($queue);
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $tracks = $playlist->tracks()->get();

        if ($tracks->isEmpty()) {
            throw new \RuntimeException("Playlist {$playlist->id} has no tracks.");
        }

        $this->playLibraryTracks($tracks);
    }

    /** The server's own file for the track, in a format this player decodes (itself or as a proxy). */
    public static function playable(Track $track, Device $device): bool
    {
        if (!$track->isOwnFile()) {
            return false;
        }

        return PlayerDevice::source($device, self::mimeOf($track)) !== false;
    }

    private static function mimeOf(Track $track): ?string
    {
        return $track->trackFiles()->with('file')->get()->pluck('file.mime_type')->filter()->first();
    }

    /** Called when the player reports that a track ended: plays the next one, or stops at the end. */
    public function trackEnded(): void
    {
        $queue = DeviceQueue::for($this->device->id);

        if ($queue->advance() === null) {
            $queue->save($this->device->id);
            $this->send('stop');

            return;
        }

        $queue->save($this->device->id);
        $this->playCurrent($queue);
    }

    private function startTrack(\Closure $move): void
    {
        $queue = DeviceQueue::for($this->device->id);

        if ($move($queue) === null) {
            $this->send('stop');

            return;
        }

        $queue->save($this->device->id);
        $this->playCurrent($queue);
    }

    private function playCurrent(DeviceQueue $queue): void
    {
        $track = Track::with(['artist', 'album'])->find($queue->current());

        if (!$track) {
            throw new \RuntimeException('The queue has no playable track.');
        }

        $mime = self::mimeOf($track);
        $profile = PlayerDevice::source($this->device, $mime);

        if ($profile === false) {
            throw new \RuntimeException("Track {$track->id} is in a format this player cannot play.");
        }

        if ($profile !== null && !Proxies::request($track, $profile, $this->device->id)) {
            if (!Proxies::original($track) || !Proxies::enabled()) {
                throw new \RuntimeException("Track {$track->id} cannot be converted to {$profile}.");
            }

            // The proxy is being made: stop what plays now; proxyReady() sends the play when it is there.
            $this->send('pause');

            return;
        }

        self::forgetStream($this->device->id);
        $artwork = LibraryItemArtwork::forUrl(PlayerDevice::cover($track));

        $this->send('play', ['track' => [
            'id' => $track->id,
            'url' => '/stream/'.$track->id.($profile ? '?profile='.$profile : ''),
            'profile' => $profile,
            'mime' => $profile ? Proxies::mime($profile) : $mime,
            'name' => $track->name,
            'artist' => $track->artist?->name,
            'album' => $track->album?->name,
            'duration' => $track->duration,
            'images' => array_values(array_filter([LibraryItemArtwork::webUrl($artwork['proxy_320'] ?? null)])),
            'lite' => $artwork ? '/api/artwork/'.$artwork['hash'] : null,
        ]]);
    }

    /** A proxy this player waited for is made: plays it if that track is still the current one. */
    public function proxyReady(int $trackId): void
    {
        $queue = DeviceQueue::for($this->device->id);

        if ((int) $queue->current() === $trackId) {
            $this->playCurrent($queue);
        }
    }

    /** The proxy could not be made: nothing plays, rather than a format the player cannot decode. */
    public function proxyFailed(int $trackId): void
    {
        Log::warning("Player {$this->device->id}: no proxy for track {$trackId}, stopping.");
        $this->send('stop');
    }

    private function reportModes(DeviceQueue $queue): void
    {
        event(new PlaybackModesUpdated(
            deviceId: (string) $this->device->id,
            modes: new \App\Domain\Device\PlaybackModes($queue->shuffle, RepeatMode::from($queue->repeat), null),
        ));
    }

    private function send(string $op, array $payload = []): void
    {
        PlayerCommands::send($this->device, $op, $payload);
    }
}
