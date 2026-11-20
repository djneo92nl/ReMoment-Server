<?php

namespace Tests\Support;

use App\Integrations\Cast\CastConnection;

/** A Cast receiver that is only a script: what it answers, and what was sent to it. */
class FakeCastConnection extends CastConnection
{
    /** @var array<string, mixed> */
    public static array $receiver = [];

    /** @var array<int, array<string, mixed>> */
    public static array $media = [];

    /** @var array<int, array{0: string, 1: string, 2: array}> destination, namespace, payload */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$receiver = ['applications' => [['appId' => 'CC1AD845', 'displayName' => 'Default Media Receiver', 'transportId' => 'web-1']]];
        self::$media = [['mediaSessionId' => 7, 'playerState' => 'PLAYING']];
        self::$requests = [];
    }

    public function connect(): void {}

    public function close(): void {}

    public function receiverStatus(): array
    {
        return self::$receiver;
    }

    public function mediaStatus(string $transportId): array
    {
        return self::$media;
    }

    public function request(string $destination, string $namespace, array $payload): array
    {
        self::$requests[] = [$destination, $namespace, $payload];

        return [];
    }
}
