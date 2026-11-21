<?php

namespace App\Domain\MediaServers;

use App\Integrations\Contracts\ContinueWatchingInterface;
use App\Integrations\Contracts\MediaServerDriverInterface;
use App\Integrations\Contracts\NextUpInterface;
use App\Integrations\Contracts\PlaysOnClientInterface;
use App\Integrations\Contracts\ReportsClientNowPlayingInterface;

/** Capability strings of a media server, from the contracts its driver implements (like Domain\Device\Capabilities). */
final class MediaServerCapabilities
{
    public const CONTRACTS = [
        'continue_watching' => ContinueWatchingInterface::class,
        'next_up' => NextUpInterface::class,
        'play_on_client' => PlaysOnClientInterface::class,
        'sessions' => ReportsClientNowPlayingInterface::class,
    ];

    /** @return list<string> */
    public static function for(MediaServerDriverInterface $driver): array
    {
        $capabilities = [];
        foreach (self::CONTRACTS as $name => $contract) {
            if ($driver instanceof $contract) {
                $capabilities[] = $name;
            }
        }

        return $capabilities;
    }
}
