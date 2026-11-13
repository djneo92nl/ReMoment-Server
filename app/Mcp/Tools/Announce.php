<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Announcements: play a short clip (a chime, or a clip the admin uploaded) on one or more speakers; each speaker goes back to what it played afterwards. action: clips (list them), play (needs clip and device_ids; optional volume 0-100 for the clip). Devices need the stream_url capability. Full shapes: docs/api/announcements.md.')]
class Announce extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['clips', 'play'])->required(),
            'clip' => $schema->string()->description('play: the clip name, e.g. chime'),
            'device_ids' => $schema->array()->items($schema->integer())->description('play: the devices'),
            'volume' => $schema->integer()->min(0)->max(100)->description('play: volume during the clip'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:clips,play', 'clip' => 'nullable|string', 'device_ids' => 'nullable|array', 'device_ids.*' => 'integer',
            'volume' => 'nullable|integer|min:0|max:100',
        ]);

        return $data['action'] === 'clips'
            ? $this->api('GET', 'announcements/clips')
            : $this->api('POST', 'announcements', $this->filled(['clip' => $data['clip'] ?? null, 'device_ids' => $data['device_ids'] ?? null, 'volume' => $data['volume'] ?? null]));
    }
}
