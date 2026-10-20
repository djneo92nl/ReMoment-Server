<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Spotify through the server\'s librespot stream. Without device_id: whether it is enabled, the receiver\'s name and the stream URL. With device_id: start the stream on that device (needs `stream_url`); the user then picks the receiver by name in Spotify.')]
class SpotifyStream extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->description('Device that should play the stream'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'nullable|integer']);

        return isset($data['device_id'])
            ? $this->api('POST', "devices/{$data['device_id']}/spotify-stream")
            : $this->api('GET', 'spotify-stream');
    }
}
