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
        return Http::timeout(3)
            ->withHeaders(['Authorization' => 'MediaBrowser Token="'.ServiceCredentials::value('jellyfin', 'api_key').'"'])
            ->get(self::baseUrl().'/Sessions')
            ->throw()
            ->json() ?? [];
    }
}
