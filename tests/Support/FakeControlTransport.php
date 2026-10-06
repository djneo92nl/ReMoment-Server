<?php

namespace Tests\Support;

use App\Domain\Control\ControlProfile;
use App\Domain\Control\ControlTransport;
use App\Models\Device;

/** Records sent commands; fails for the command id "broken". */
class FakeControlTransport implements ControlTransport
{
    /** @var array<int, array{int, string}> */
    public static array $sent = [];

    public function supports(Device $device, ControlProfile $profile): bool
    {
        return true;
    }

    public function send(Device $device, ControlProfile $profile, string $commandId): void
    {
        if ($commandId === 'broken') {
            throw new \RuntimeException('boom');
        }

        self::$sent[] = [$device->id, $commandId];
    }
}
