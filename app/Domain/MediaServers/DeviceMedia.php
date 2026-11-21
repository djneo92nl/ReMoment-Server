<?php

namespace App\Domain\MediaServers;

use App\Domain\Device\DeviceCache;
use App\Domain\Media\MediaItem;
use App\Domain\Media\NowPlaying;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\ContinueWatchingInterface;
use App\Integrations\Contracts\NextUpInterface;
use App\Integrations\Contracts\PlaysOnClientInterface;
use App\Integrations\Contracts\ReportsClientNowPlayingInterface;
use App\Models\Device;
use App\Models\MediaServer;
use Illuminate\Support\Facades\Cache;

/**
 * The media of one device: the shelves of every server that applies to it (continue watching, next up), whether its
 * client app is waiting in front, starting an item there, and what the servers say plays on it. The web card, the REST
 * API (`/api/devices/{id}/media` and the Jellyfin routes on top) and the MCP tools share it (docs/architecture/media-servers.md).
 */
final class DeviceMedia
{
    /** How long a server's shelves are kept. */
    public const SHELF_SECONDS = 60;

    /** The device has at least one server (of that type) to show or play from. */
    public static function available(Device $device, ?string $type = null): bool
    {
        return MediaServers::forDevice($device, $type)->isNotEmpty();
    }

    /** True while the device shows a server's client app and nothing plays in it. */
    public static function idle(Device $device, ?string $type = null): bool
    {
        $packages = MediaServers::clientPackages(MediaServers::forDevice($device, $type));
        $now = DeviceCache::getNowPlaying($device->id);

        return $now !== null && $now->track === null && in_array($now->source?->connector, $packages, true);
    }

    /**
     * The shelves of the device's servers, merged in server order. Each server is read (and cached) on its own: a
     * server that does not answer is listed with `ok: false` and the others still show.
     *
     * @return array{servers: list<array{id: int, name: string, driver: string, ok: bool}>, continue_watching: list<array<string, mixed>>, next_up: list<array<string, mixed>>}|null
     *                                                                                                                                                                               null when the device has no server or none answered
     */
    public static function shelves(Device $device, ?string $type = null): ?array
    {
        $result = ['servers' => [], 'continue_watching' => [], 'next_up' => []];

        foreach (MediaServers::forDevice($device, $type) as $server) {
            $shelf = self::shelf($server);

            $result['servers'][] = ['id' => $server->id, 'name' => $server->name, 'driver' => $server->driver, 'ok' => $shelf !== null];

            if ($shelf !== null) {
                $result['continue_watching'] = [...$result['continue_watching'], ...$shelf['continue_watching']];
                $result['next_up'] = [...$result['next_up'], ...$shelf['next_up']];
            }
        }

        return collect($result['servers'])->contains('ok', true) ? $result : null;
    }

    /**
     * Starts an item in the server's client app on the device.
     *
     * @param  string  $item  `3:abc` (server 3's item abc) or a bare item id with `$serverId`, or alone when one server applies
     * @param  float  $position  seconds
     *
     * @throws UnsupportedOperationException when no server applies, or the choice is ambiguous or cannot play
     * @throws \RuntimeException when the client app is not connected to the server
     */
    public static function play(Device $device, string $item, ?int $serverId = null, float $position = 0, ?string $type = null): void
    {
        [$namespaced, $itemId] = MediaItem::parse($item);
        $serverId ??= $namespaced;
        $servers = MediaServers::forDevice($device, $type);
        $server = $serverId !== null ? $servers->firstWhere('id', $serverId) : ($servers->count() === 1 ? $servers->first() : null);

        if ($server === null) {
            throw new UnsupportedOperationException($servers->isEmpty()
                ? 'This device has no media server: it needs a server with complete credentials that can play on it (Settings › Services).'
                : ($serverId === null ? 'Several media servers apply to this device: name the server (an item id like 3:abc, or `server`).' : "Media server {$serverId} does not apply to this device."));
        }

        $driver = MediaServers::driver($server);

        if (!$driver instanceof PlaysOnClientInterface) {
            throw new UnsupportedOperationException("{$server->name} cannot start an item on a device.");
        }

        $driver->playOnClient($device, $itemId, $position);
    }

    /** What the device's servers say plays in their client app on it (the first that reports something); null when none does. */
    public static function nowPlaying(Device $device): ?NowPlaying
    {
        foreach (MediaServers::forDevice($device) as $server) {
            $driver = MediaServers::driver($server);

            if ($driver instanceof ReportsClientNowPlayingInterface && ($playing = $driver->nowPlayingOn($device)) !== null) {
                return $playing;
            }
        }

        return null;
    }

    /** @return array{continue_watching: list<array<string, mixed>>, next_up: list<array<string, mixed>>}|null null when the server does not answer */
    private static function shelf(MediaServer $server): ?array
    {
        return Cache::remember("media:shelf:{$server->id}", self::SHELF_SECONDS, function () use ($server) {
            try {
                $driver = MediaServers::driver($server);

                return [
                    'continue_watching' => $driver instanceof ContinueWatchingInterface ? self::arrays($driver->continueWatching()) : [],
                    'next_up' => $driver instanceof NextUpInterface ? self::arrays($driver->nextUp()) : [],
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** @param  list<MediaItem>  $items @return list<array<string, mixed>> */
    private static function arrays(array $items): array
    {
        return array_map(fn (MediaItem $item) => $item->toArray(), $items);
    }
}
