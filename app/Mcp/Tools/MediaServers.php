<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Media servers (Jellyfin; more types later). Without `device_id`: list the servers and what each can do. With `device_id`: that device\'s "continue watching" and "next up" from every server that applies to it; add `item` (the `id` of a listed item, like 3:abc, optionally `position` in seconds to resume) to start it in the server\'s app on that device.')]
class MediaServers extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->description('The device (a TV) to list the media of or play on; omit to list the servers'),
            'item' => $schema->string()->description('Item to play: the `id` from the list (server id and item id, 3:abc)'),
            'server' => $schema->integer()->description('Server id, only when `item` is a bare item id and several servers apply'),
            'position' => $schema->number()->description('Start position in seconds, from the list\'s resume_position'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'nullable|integer', 'item' => 'nullable|string', 'server' => 'nullable|integer', 'position' => 'nullable|numeric']);

        if (!isset($data['device_id'])) {
            return $this->api('GET', 'media-servers');
        }

        $path = "devices/{$data['device_id']}/media";

        return isset($data['item'])
            ? $this->api('POST', "$path/play", $this->filled(['item' => $data['item'], 'server' => $data['server'] ?? null, 'position' => $data['position'] ?? null]))
            : $this->api('GET', $path);
    }
}
