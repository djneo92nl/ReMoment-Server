<?php

namespace Tests\Support;

use App\Domain\Device\AvailableSource;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\QueueItem;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

/**
 * Bound as a device's `device_driver` in API tests. Implements every
 * contract the REST API exposes, records calls, and can be told to throw
 * to simulate a device that doesn't respond.
 */
class FakePlayerDriver implements MediaControlsInterface, MultiRoomInterface, MusicPlayerDriverInterface, QueueInterface, SeekInterface, SourceActivationInterface, SourcesInterface, VolumeControlInterface
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public static array $calls = [];

    public static ?\Exception $throw = null;

    public static int $volume = 30;

    public static bool $muted = false;

    /** @var string[] */
    public static array $peers = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
        self::$volume = 30;
        self::$muted = false;
        self::$peers = [];
    }

    private function record(string $method, mixed $argument = null): void
    {
        if (self::$throw) {
            throw self::$throw;
        }

        self::$calls[] = [$method, $argument];
    }

    public function getCurrentPlayingAttribute(): array
    {
        // Like the real drivers: live state comes from what listeners cached.
        return DeviceCache::getNowPlaying($this->device->id)?->toArray() ?? [];
    }

    public function play(): void
    {
        $this->record('play');
    }

    public function pause(): void
    {
        $this->record('pause');
    }

    public function next(): void
    {
        $this->record('next');
    }

    public function previous(): void
    {
        $this->record('previous');
    }

    public function stop(): void
    {
        $this->record('stop');
    }

    public function seek(int $seconds): void
    {
        $this->record('seek', $seconds);
    }

    public function setVolume(int $volume): int
    {
        $this->record('setVolume', $volume);

        return self::$volume = $volume;
    }

    public function getVolume(): int
    {
        return self::$volume;
    }

    public function incrementVolume(): void
    {
        $this->record('incrementVolume');
    }

    public function decrementVolume(): void
    {
        $this->record('decrementVolume');
    }

    public function mute(): void
    {
        $this->record('mute');
        self::$muted = true;
    }

    public function unmute(): void
    {
        $this->record('unmute');
        self::$muted = false;
    }

    public function isMuted(): bool
    {
        $this->record('isMuted');

        return self::$muted;
    }

    public function getUpNext(int $limit = 20): array
    {
        $this->record('getUpNext', $limit);

        return array_slice([
            new QueueItem('Next Song', 'Next Artist', 'Next Album', 'https://img.test/1.jpg', 200, 'fake:1'),
            new QueueItem('Later Song'),
        ], 0, $limit);
    }

    public function getSources(): array
    {
        $this->record('getSources');

        return [new AvailableSource('MUSIC', 'Music', 'MUSIC', 'music', true, false, 'provider@jid.test', 'Spotify')];
    }

    public function activateSource(string $sourceId): void
    {
        $this->record('activateSource', $sourceId);
    }

    public function multiRoomMetaKey(): string
    {
        return 'fake_id';
    }

    public function getMultiRoomId(): ?string
    {
        return "fake-{$this->device->id}";
    }

    public function getJoinablePeerIds(): array
    {
        return self::$peers;
    }

    public function getCurrentPeerIds(): array
    {
        return [];
    }

    public function joinSession(Device $hostDevice): void
    {
        $this->record('joinSession', $hostDevice->id);
    }

    public function leaveSession(): void
    {
        $this->record('leaveSession');
    }
}
