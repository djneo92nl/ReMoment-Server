<?php

namespace App\Integrations\ReMoment;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Domain\Storage\StreamUrl;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/**
 * A ReMoment player (browser tab, app, ESP32) that plays the server's own files. The player does the
 * playing; this driver keeps the queue and turns every control into a command for it (PlayerCommands).
 */
class PlayerDriver implements LibraryPlaybackInterface, MediaControlsInterface, MusicPlayerDriverInterface, QueueInterface, QueueJumpInterface, RepeatInterface, SeekInterface, ShuffleInterface, VolumeControlInterface
{
    public function __construct(public Device $device) {}

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
        $this->send('stop');
    }

    public function next(): void
    {
        $this->startTrack(fn (PlayerQueue $queue) => $queue->advance(manual: true));
    }

    public function previous(): void
    {
        $this->startTrack(fn (PlayerQueue $queue) => $queue->back());
    }

    public function seek(int $seconds): void
    {
        $this->send('seek', ['position' => max(0, $seconds)]);
    }

    public function skipToQueuePosition(int $position): void
    {
        $this->startTrack(fn (PlayerQueue $queue) => $queue->jump(max(1, $position)));
    }

    public function getUpNext(int $limit = 20): array
    {
        $ids = PlayerQueue::for($this->device->id)->upNext($limit);
        $tracks = Track::with(['artist', 'album'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->filter(fn ($id) => $tracks->has($id))->map(function ($id) use ($tracks) {
            $track = $tracks[$id];

            return new QueueItem(
                name: $track->name,
                artist: $track->artist?->name,
                album: $track->album?->name,
                image: ($track->album?->images ?? [])[0] ?? ($track->images ?? [])[0] ?? null,
                duration: $track->duration,
                uri: StreamUrl::for($track->id),
            );
        })->values()->all();
    }

    public function setShuffle(bool $shuffle): void
    {
        $queue = PlayerQueue::for($this->device->id);
        $queue->shuffle = $shuffle;

        if ($shuffle) {
            $rest = array_slice($queue->trackIds, $queue->index + 1);
            shuffle($rest);
            $queue->trackIds = array_merge(array_slice($queue->trackIds, 0, $queue->index + 1), $rest);
        }

        $queue->save($this->device->id);
        $this->reportModes($queue);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $queue = PlayerQueue::for($this->device->id);
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

        $queue = PlayerQueue::for($this->device->id);
        $queue->trackIds = $playable->pluck('id')->all();
        $queue->index = 0;
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

    /** The server's own file for the track, in a format this player decodes. */
    public static function playable(Track $track, Device $device): bool
    {
        if (!$track->isOwnFile()) {
            return false;
        }

        $mime = $track->trackFiles()->with('file')->get()->pluck('file.mime_type')->filter()->first();

        return $mime === null || in_array($mime, PlayerDevice::formats($device), true);
    }

    /** Called when the player reports that a track ended: plays the next one, or stops at the end. */
    public function trackEnded(): void
    {
        $queue = PlayerQueue::for($this->device->id);

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
        $queue = PlayerQueue::for($this->device->id);

        if ($move($queue) === null) {
            $this->send('stop');

            return;
        }

        $queue->save($this->device->id);
        $this->playCurrent($queue);
    }

    private function playCurrent(PlayerQueue $queue): void
    {
        $track = Track::with(['artist', 'album'])->find($queue->current());

        if (!$track) {
            throw new \RuntimeException('The queue has no playable track.');
        }

        $this->send('play', ['track' => [
            'id' => $track->id,
            'url' => '/stream/'.$track->id,
            'name' => $track->name,
            'artist' => $track->artist?->name,
            'album' => $track->album?->name,
            'duration' => $track->duration,
            'images' => array_values(array_filter([($track->album?->images ?? [])[0] ?? ($track->images ?? [])[0] ?? null])),
        ]]);
    }

    private function reportModes(PlayerQueue $queue): void
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
