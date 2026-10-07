<?php

namespace Tests\Support;

use App\Domain\Device\AvailableSource;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Models\Device;

/** A driver with one input, recording what it is switched to. */
class FakeSourceDriver implements MusicPlayerDriverInterface, SourceActivationInterface, SourcesInterface
{
    /** @var string[] */
    public static array $activated = [];

    public static ?\Exception $throw = null;

    public function __construct(public Device $device) {}

    public function getSources(): array
    {
        return [new AvailableSource('tv', 'TV', 'TV', 'video', false, false, null, null),
            new AvailableSource('spotify', 'Spotify', 'SPOTIFY', 'music', false, true, 'jid', 'Beolab 28'),
        ];
    }

    public function activateSource(string $sourceId): void
    {
        if (self::$throw) {
            throw self::$throw;
        }

        self::$activated[] = $sourceId;
    }
}
