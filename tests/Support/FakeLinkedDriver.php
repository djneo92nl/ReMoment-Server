<?php

namespace Tests\Support;

use App\Domain\Device\AvailableSource;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\ReportsActiveSource;
use App\Integrations\Contracts\ShuffleInterface;

/**
 * A host or a sub device of a link: everything FakePlayerDriver does, power, and a source report. Every call is also
 * logged with the id of the device it reached, `[deviceId, method, argument]`, so a test can see which of two devices
 * a command went to.
 */
class FakeLinkedDriver extends FakePlayerDriver implements PowerInterface, ReportsActiveSource, ShuffleInterface
{
    /** @var array<int, array{0: int, 1: string, 2: mixed}> */
    public static array $log = [];

    /** What `getSources()` lists, null for FakePlayerDriver's single source. @var AvailableSource[]|null */
    public static ?array $sourceList = null;

    public static function reset(): void
    {
        FakePlayerDriver::reset();
        self::$log = [];
        self::$sourceList = null;
    }

    public function getSources(): array
    {
        return self::$sourceList ?? parent::getSources();
    }

    protected function record(string $method, mixed $argument = null): void
    {
        parent::record($method, $argument);

        self::$log[] = [$this->device->id, $method, $argument];
    }

    public function powerOn(): void
    {
        $this->record('powerOn');
    }

    public function standby(): void
    {
        $this->record('standby');
    }

    public function setShuffle(bool $shuffle): void
    {
        $this->record('setShuffle', $shuffle);
    }

    /** What reached this device, `[method, argument]` in order. */
    public static function callsOn(int $deviceId): array
    {
        return array_values(array_map(fn (array $entry) => [$entry[1], $entry[2]], array_filter(self::$log, fn (array $entry) => $entry[0] === $deviceId)));
    }
}
