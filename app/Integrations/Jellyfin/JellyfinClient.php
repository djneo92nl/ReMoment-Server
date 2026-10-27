<?php

namespace App\Integrations\Jellyfin;

use App\Domain\Credentials\ServiceCredentials;
use Illuminate\Support\Facades\Http;

/** The Jellyfin server's REST API, read-only here: the sessions (logged-in clients) and what each plays. */
class JellyfinClient
{
    public static function configured(): bool
    {
        return ServiceCredentials::configured('jellyfin');
    }

    public static function baseUrl(): string
    {
        return ServiceCredentials::baseUrl('jellyfin');
    }

    /** @return array<int, array<string, mixed>> `GET /Sessions`: Client, DeviceName, RemoteEndPoint, NowPlayingItem, PlayState */
    public function sessions(): array
    {
        return $this->get('/Sessions');
    }

    /** The user whose watch list is shown: the configured one, else the server's first. */
    public function userId(): ?string
    {
        $configured = ServiceCredentials::value('jellyfin', 'user_id');

        return $configured !== '' ? $configured : ($this->get('/Users')[0]['Id'] ?? null);
    }

    /** @return array<int, array<string, mixed>> items the user started and did not finish */
    public function resume(string $userId, int $limit = 12): array
    {
        return $this->get("/Users/{$userId}/Items/Resume", ['Limit' => $limit, 'MediaTypes' => 'Video', 'Fields' => 'Overview'])['Items'] ?? [];
    }

    /** @return array<int, array<string, mixed>> the next unwatched episode of each series being followed */
    public function nextUp(string $userId, int $limit = 12): array
    {
        return $this->get('/Shows/NextUp', ['userId' => $userId, 'Limit' => $limit])['Items'] ?? [];
    }

    /** The session of the Jellyfin app on the device at `$ip`: what a play command is addressed to. */
    public function sessionIdFor(string $ip): ?string
    {
        foreach ($this->sessions() as $session) {
            if (preg_replace('/^::ffff:/', '', (string) ($session['RemoteEndPoint'] ?? '')) === $ip && ($session['SupportsRemoteControl'] ?? true)) {
                return $session['Id'] ?? null;
            }
        }

        return null;
    }

    /** Starts an item on a client (`POST /Sessions/{id}/Playing`, PlayNow), from a position in ticks (10 000 000 per second). */
    public function play(string $sessionId, string $itemId, int $startTicks = 0): void
    {
        Http::timeout(5)
            ->withHeaders($this->headers())
            ->post(self::baseUrl()."/Sessions/{$sessionId}/Playing?".http_build_query(['playCommand' => 'PlayNow', 'itemIds' => $itemId, 'startPositionTicks' => $startTicks]))
            ->throw();
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        return Http::timeout(4)->withHeaders($this->headers())->get(self::baseUrl().$path, $query)->throw()->json() ?? [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'MediaBrowser Token="'.ServiceCredentials::value('jellyfin', 'api_key').'"'];
    }
}
