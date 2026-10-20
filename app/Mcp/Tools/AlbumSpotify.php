<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('An album as Spotify has it. action: tracks (Spotify\'s tracks in album order, each with the library `track_id` that holds it or null, and how many are missing) or fill (add the missing tracks to the library album). Errors: spotify_unavailable (422), not found (404).')]
class AlbumSpotify extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['tracks', 'fill'])->required(),
            'album_id' => $schema->integer()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['action' => 'required|in:tracks,fill', 'album_id' => 'required|integer']);

        return $data['action'] === 'tracks'
            ? $this->api('GET', "library/albums/{$data['album_id']}/spotify")
            : $this->api('POST', "library/albums/{$data['album_id']}/fill-from-spotify");
    }
}
