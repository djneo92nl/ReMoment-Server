<?php

namespace Tests\Support;

use App\Domain\Device\RemoteKey;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\RemoteControlInterface;
use App\Models\Device;

/** A TV with a small remote: a D-pad with OK, Back, a volume pair and the red key. */
class FakeRemoteDriver implements MusicPlayerDriverInterface, RemoteControlInterface
{
    /** @var string[] */
    public static array $pressed = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$pressed = [];
    }

    public function supportedKeys(): array
    {
        return [RemoteKey::Up, RemoteKey::Down, RemoteKey::Left, RemoteKey::Right, RemoteKey::Ok, RemoteKey::Back, RemoteKey::VolumeUp, RemoteKey::VolumeDown, RemoteKey::Red];
    }

    public function sendKey(RemoteKey $key): void
    {
        self::$pressed[] = $key->value;
    }
}
