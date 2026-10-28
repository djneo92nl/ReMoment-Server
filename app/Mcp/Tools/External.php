<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Browse and play what an external service (Spotify for now) offers, also artists the library does not have. action: search (needs q and type artist|album|track), artist (needs id: the artist and its albums), album (needs id: the album and its tracks), playlists (the playlists of the account on the service, read live), playlist (needs id: the playlist and its tracks; tracks is null when the service does not share them), play (needs device_id, type artist|album|track|playlist, id; optional shuffle: plays on that device without adding it to the library). Results say `in_library`. provider defaults to spotify. Full shapes: docs/api/availability.md.')]
class External extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['search', 'artist', 'album', 'playlists', 'playlist', 'play'])->required(),
            'provider' => $schema->string()->description('Default spotify'),
            'q' => $schema->string()->description('search: the search text (2 or more characters)'),
            'type' => $schema->string()->enum(['artist', 'album', 'track', 'playlist'])->description('search (artist|album|track), play, add'),
            'id' => $schema->string()->description("The service's id of the artist, album or track"),
            'device_id' => $schema->integer()->description('play: the device'),
            'shuffle' => $schema->boolean()->description('play: shuffle'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:search,artist,album,playlists,playlist,play',
            'provider' => 'nullable|string|max:32', 'q' => 'nullable|string', 'type' => 'nullable|in:artist,album,track,playlist', 'id' => 'nullable|string',
            'device_id' => 'nullable|integer', 'shuffle' => 'nullable|boolean',
        ]);
        $provider = rawurlencode($data['provider'] ?? 'spotify');
        $need = fn (string ...$keys) => array_values(array_filter($keys, fn ($k) => ($data[$k] ?? null) === null || $data[$k] === ''));

        return match ($data['action']) {
            'search' => ($missing = $need('q', 'type')) ? Response::error('invalid (422): search needs '.implode(' and ', $missing).'.')
                : $this->api('GET', "library/external/{$provider}/search", ['q' => $data['q'], 'type' => $data['type']]),
            'playlists' => $this->api('GET', "library/external/{$provider}/playlists"),
            'artist', 'album', 'playlist' => ($missing = $need('id')) ? Response::error("invalid (422): {$data['action']} needs an id.")
                : $this->api('GET', "library/external/{$provider}/{$data['action']}s/".rawurlencode($data['id'])),
            default => ($missing = $need('device_id', 'type', 'id')) ? Response::error('invalid (422): play needs '.implode(', ', $missing).'.')
                : $this->api('POST', "devices/{$data['device_id']}/library/play-external", $this->filled(['provider' => $data['provider'] ?? 'spotify', 'type' => $data['type'], 'id' => $data['id'], 'shuffle' => $data['shuffle'] ?? null])),
        };
    }
}
