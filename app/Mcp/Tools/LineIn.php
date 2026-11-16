<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Line-in on a ReMoment Node (turntable, CD player) as a stream, 2 to 5 s delay (not for TV). Without device_id: the line-ins, whether they are live and who plays them. With device_id (needs `stream_url`): play the line-in on that device; node_id when there is more than one.')]
class LineIn extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->description('Device that should play the line-in'),
            'node_id' => $schema->integer()->description('Node (its id in the list) when more than one has a line-in'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'nullable|integer', 'node_id' => 'nullable|integer']);

        return isset($data['device_id'])
            ? $this->api('POST', "devices/{$data['device_id']}/line-in", $this->filled(['node_id' => $data['node_id'] ?? null]))
            : $this->api('GET', 'line-ins');
    }
}
