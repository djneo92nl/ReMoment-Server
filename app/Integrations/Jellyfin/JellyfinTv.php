<?php

namespace App\Integrations\Jellyfin;

use App\Domain\Device\DeviceCache;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * Jellyfin on one TV: whether its Jellyfin app is in front with nothing playing, the lists to pick from
 * (continue watching, next up) and starting an item there. The web card, the REST API and the MCP tool share it.
 */
final class JellyfinTv
{
    public const PACKAGE = 'org.jellyfin.androidtv';

    /** A TV the Jellyfin card applies to: a Philips Android TV driver, and a server key set. */
    public static function available(Device $device): bool
    {
        return JellyfinClient::configured() && $device->driver instanceof \App\Integrations\Philips\VideoPlayerDriver;
    }

    /** True while the TV shows its Jellyfin app and nothing plays in it. */
    public static function idle(Device $device): bool
    {
        $now = DeviceCache::getNowPlaying($device->id);

        return $now?->source?->connector === self::PACKAGE && $now->track === null;
    }

    /**
     * @return array{continue_watching: array, next_up: array}|null null when the server does not answer
     */
    public static function lists(Device $device): ?array
    {
        return Cache::remember("jellyfin:shelf:{$device->id}", 60, function () {
            try {
                $client = new JellyfinClient;
                $user = $client->userId();

                return $user === null ? null : [
                    'continue_watching' => JellyfinShelf::tiles($client->resume($user), JellyfinClient::baseUrl()),
                    'next_up' => JellyfinShelf::tiles($client->nextUp($user), JellyfinClient::baseUrl()),
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * @throws \RuntimeException when the Jellyfin app on the TV is not connected to the server
     */
    public static function play(Device $device, string $itemId, int $positionTicks = 0): void
    {
        $client = new JellyfinClient;
        $session = $client->sessionIdFor((string) $device->ip_address);

        if ($session === null) {
            throw new \RuntimeException('The Jellyfin app on the TV is not connected to the server.');
        }

        $client->play($session, $itemId, $positionTicks);
    }
}
