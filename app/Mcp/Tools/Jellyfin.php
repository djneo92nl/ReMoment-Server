<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Jellyfin on a TV (a Philips TV with a Jellyfin key set). Omit `item_id` to list "continue watching" and "next up"; give an item_id (from that list, optionally position_ticks to resume) to start it in the Jellyfin app on the TV.')]
class Jellyfin extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'item_id' => $schema->string()->description('Jellyfin item to play; omit to list'),
            'position_ticks' => $schema->integer()->description('Start position in ticks (10 000 000 per second), from the list'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'item_id' => 'nullable|string', 'position_ticks' => 'nullable|integer']);
        $path = "devices/{$data['device_id']}/jellyfin";

        return isset($data['item_id'])
            ? $this->api('POST', "$path/play", $this->filled(['item_id' => $data['item_id'], 'position_ticks' => $data['position_ticks'] ?? null]))
            : $this->api('GET', $path);
    }
}
