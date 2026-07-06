<?php

namespace App\Integrations\Spotify\Services;

use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Playlist;
use App\Services\SpotifyTokenService;

class PlaylistPlaybackService
{
    public function __construct(private SpotifyTokenService $tokenService) {}

    public function playOnDevice(Playlist $playlist, Device $device): void
    {
        $connectName = DeviceMeta::where('device_id', $device->id)
            ->where('key', 'spotify_connect_name')
            ->value('value');

        if ($connectName === null) {
            throw new \RuntimeException("{$device->device_name} has no Spotify Connect mapping. Configure one in Settings → Spotify Connect.");
        }

        $api = $this->tokenService->makeApiClient();
        $spotifyDevices = $api->getMyDevices()['devices'] ?? [];
        $match = collect($spotifyDevices)->firstWhere('name', $connectName);

        if ($match === null) {
            throw new \RuntimeException("No active Spotify Connect device named \"{$connectName}\" found. Make sure it's powered on.");
        }

        $spotifyPlaylistId = str_replace('spotify:playlist:', '', (string) $playlist->external_id);

        $api->play($match['id'], ['context_uri' => "spotify:playlist:{$spotifyPlaylistId}"]);
    }
}
