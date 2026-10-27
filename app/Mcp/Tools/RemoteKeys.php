<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Remote control keys of a TV (devices with the remote_control capability). Omit `key` to list the keys the device accepts; give one (up, down, left, right, ok, back, home, menu, source, info, play, pause, stop, next, previous, fast_forward, rewind, volume_up, volume_down, mute, channel_up, channel_down, red, green, yellow, blue) to press it.')]
class RemoteKeys extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'key' => $schema->string()->description('Key to press; omit to list the supported keys'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'key' => 'nullable|string']);
        $path = "devices/{$data['device_id']}/keys";

        return isset($data['key']) ? $this->api('POST', $path, ['key' => $data['key']]) : $this->api('GET', $path);
    }
}
