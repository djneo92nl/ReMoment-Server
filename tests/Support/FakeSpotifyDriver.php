<?php

namespace Tests\Support;

use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Models\Device;

/**
 * Bound in the container in place of the Spotify MusicPlayerDriver, so a
 * device with the real Spotify driver class records calls instead of
 * reaching the Spotify Web API.
 */
class FakeSpotifyDriver implements LikeInterface, MediaControlsInterface, MusicPlayerDriverInterface, QueueInterface, RepeatInterface, SeekInterface, ShuffleInterface
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public static array $calls = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
    }

    private function record(string $method, mixed $argument = null): void
    {
        self::$calls[] = [$method, $argument];
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

    public function getUpNext(int $limit = 20): array
    {
        $this->record('getUpNext', $limit);

        return [new QueueItem('Spotify Next')];
    }

    public function setShuffle(bool $shuffle): void
    {
        $this->record('setShuffle', $shuffle);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $this->record('setRepeat', $mode);
    }

    public function setLiked(bool $liked): void
    {
        $this->record('setLiked', $liked);
    }
}
