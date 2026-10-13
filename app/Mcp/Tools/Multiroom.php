<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Multiroom: `get` lists devices this one can join and its listeners, `join` makes this device listen to host_device_id, `leave` takes it out of its session. Note some sources cannot join (e.g. Spotify to a BeoSound Moment).')]
class Multiroom extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'action' => $schema->string()->enum(['get', 'join', 'leave'])->required(),
            'host_device_id' => $schema->integer()->description('join: the device to listen to'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'action' => 'required|in:get,join,leave', 'host_device_id' => 'nullable|integer']);
        $path = 'devices/'.$data['device_id'].'/multiroom';

        return match ($data['action']) {
            'join' => $this->api('POST', "$path/join", ['host_device_id' => $data['host_device_id'] ?? null]),
            'leave' => $this->api('DELETE', "$path/leave"),
            default => $this->api('GET', $path),
        };
    }
}
