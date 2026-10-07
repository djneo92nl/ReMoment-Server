<?php

namespace Tests\Support;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\SleepTimer;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SleepTimerInterface;
use App\Models\Device;

/** Bound as a device's `device_driver` in sleep timer / digits API tests. */
class FakeSleepDigitsDriver implements DigitsInterface, MusicPlayerDriverInterface, SleepTimerInterface
{
    /** @var int[] */
    public static array $digits = [];

    public static int $minutes = 0;

    public static bool $writable = true;

    public static ?\Exception $throw = null;

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$digits = [];
        self::$minutes = 0;
        self::$writable = true;
        self::$throw = null;
    }

    public function getCurrentPlayingAttribute(): array
    {
        return [];
    }

    public function sendDigit(int $digit): void
    {
        $this->failIfAsked();
        self::$digits[] = $digit;
    }

    public function getSleepTimer(): SleepTimer
    {
        $this->failIfAsked();

        return new SleepTimer(new AdjustmentRange(self::$minutes, 0, 60), self::$writable);
    }

    public function setSleepTimer(int $minutes): void
    {
        $this->failIfAsked();

        if (!self::$writable) {
            throw new UnsupportedOperationException('This device cannot set a sleep timer.');
        }
        if ($minutes < 0 || $minutes > 60) {
            throw new \InvalidArgumentException('minutes must be between 0 and 60 (step 1).');
        }

        self::$minutes = $minutes;
    }

    private function failIfAsked(): void
    {
        if (self::$throw) {
            throw self::$throw;
        }
    }
}
