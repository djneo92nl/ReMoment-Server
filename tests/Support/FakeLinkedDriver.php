<?php

namespace Tests\Support;

use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\ReportsActiveSource;

/**
 * A host or a sub device of a link: everything FakePlayerDriver does, power, and a source report. Every call is also
 * logged with the id of the device it reached, `[deviceId, method, argument]`, so a test can see which of two devices
 * a command went to.
 */
class FakeLinkedDriver extends FakePlayerDriver implements PowerInterface, ReportsActiveSource
{
    /** @var array<int, array{0: int, 1: string, 2: mixed}> */
    public static array $log = [];

    public static function reset(): void
    {
        FakePlayerDriver::reset();
        self::$log = [];
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

    /** What reached this device, `[method, argument]` in order. */
    public static function callsOn(int $deviceId): array
    {
        return array_values(array_map(fn (array $entry) => [$entry[1], $entry[2]], array_filter(self::$log, fn (array $entry) => $entry[0] === $deviceId)));
    }
}
