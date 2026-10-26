<?php

namespace Tests\Support;

use App\Domain\Device\AvailableSource;
use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\SleepTimer;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SleepTimerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VideoDeviceInterface;
use App\Models\Device;

/** A TV as the V1 reports itself: two HDMI inputs and the tuner, a few apps, a streaming service. */
class FakeTvDriver implements DigitsInterface, MusicPlayerDriverInterface, SleepTimerInterface, SourceActivationInterface, SourcesInterface, VideoDeviceInterface
{
    /** @var string[] */
    public static array $activated = [];

    /** @var int[] */
    public static array $digits = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$activated = [];
        self::$digits = [];
    }

    public function getSources(): array
    {
        return [
            new AvailableSource('tv', 'TV TUNER', 'TV', 'TV', false, false, null, null, active: false, signal: true),
            new AvailableSource('hdmi_1', 'SET-TOP BOX', 'HDMI', 'TV', true, false, null, null, input: 'HDMI 1', active: true, signal: false),
            new AvailableSource('hdmi_2', 'HDMI 2', 'HDMI', 'TV', false, false, null, null, input: 'HDMI 2', active: false, signal: true),
            new AvailableSource('youtube', 'YOUTUBE', 'YOUTUBE', 'MEDIA', true, false, null, null, active: false),
            new AvailableSource('deezer', 'DEEZER', 'DEEZER', 'MEDIA', false, false, null, null, active: false),
        ];
    }

    public function activateSource(string $sourceId): void
    {
        self::$activated[] = $sourceId;
    }

    public function sendDigit(int $digit): void
    {
        self::$digits[] = $digit;
    }

    public function getSleepTimer(): SleepTimer
    {
        return new SleepTimer(new AdjustmentRange(0, 0, 60), writable: false);
    }

    public function setSleepTimer(int $minutes): void {}
}
