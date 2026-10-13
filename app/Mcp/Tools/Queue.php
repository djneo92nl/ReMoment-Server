<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Up Next queue of a device (needs the `queue` capability): list the next tracks, or jump to a queue position (1 = next track; needs `queue_jump`).')]
class Queue extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'jump_to' => $schema->integer()->description('Position in the up-next list to skip to; omit to just list')->min(1)->max(100),
            'limit' => $schema->integer()->description('How many up-next tracks to list (1-100, default 20)')->min(1)->max(100),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'jump_to' => 'nullable|integer', 'limit' => 'nullable|integer']);
        $path = 'devices/'.$data['device_id'].'/queue';

        return isset($data['jump_to'])
            ? $this->api('POST', "$path/jump", ['position' => $data['jump_to']])
            : $this->api('GET', $path, $this->filled(['limit' => $data['limit'] ?? null]));
    }
}
