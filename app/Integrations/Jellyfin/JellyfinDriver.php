<?php

namespace App\Integrations\Jellyfin;

use App\Domain\Media\NowPlaying;
use App\Integrations\Contracts\ContinueWatchingInterface;
use App\Integrations\Contracts\MediaServerDriverInterface;
use App\Integrations\Contracts\NextUpInterface;
use App\Integrations\Contracts\PlaysOnClientInterface;
use App\Integrations\Contracts\ReportsClientNowPlayingInterface;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Models\Device;
use App\Models\MediaServer;

/**
 * Jellyfin as a media server (docs/architecture/media-servers.md): its watch lists, starting an item in the Jellyfin app
 * of a Philips Android TV and what that app plays, over `JellyfinClient`. **Unverified against a real Jellyfin server.**
 */
final class JellyfinDriver implements ContinueWatchingInterface, MediaServerDriverInterface, NextUpInterface, PlaysOnClientInterface, ReportsClientNowPlayingInterface
{
    private JellyfinClient $client;

    public function __construct(private readonly MediaServer $server)
    {
        $this->client = new JellyfinClient($server);
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /** Today only the Jellyfin app of a Philips Android TV can be driven (its package is `client_packages` in config/media-servers.php). */
    public function canPlayOn(Device $device): bool
    {
        return $device->driver instanceof VideoPlayerDriver;
    }

    public function continueWatching(int $limit = 12): array
    {
        return JellyfinItems::items($this->client->resume($this->user(), $limit), $this->server->id, $this->client->baseUrl());
    }

    public function nextUp(int $limit = 12): array
    {
        return JellyfinItems::items($this->client->nextUp($this->user(), $limit), $this->server->id, $this->client->baseUrl());
    }

    public function playOnClient(Device $device, string $itemId, float $position = 0): void
    {
        $session = $this->client->sessionIdFor((string) $device->ip_address);

        if ($session === null) {
            throw new \RuntimeException('The Jellyfin app on the TV is not connected to the server.');
        }

        $this->client->play($session, $itemId, JellyfinItems::ticks($position));
    }

    public function nowPlayingOn(Device $device): ?NowPlaying
    {
        return JellyfinNowPlaying::forClient($this->client->sessions(), (string) $device->ip_address, $this->client->baseUrl());
    }

    private function user(): string
    {
        return $this->client->userId() ?? throw new \RuntimeException('The Jellyfin server has no user.');
    }
}
