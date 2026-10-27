<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('What a Bang & Olufsen source plays, through the server\'s BeoLink stream (pseudo multiroom for devices outside the B&O ecosystem). Without device_id: whether it is enabled, the stream URL, which B&O device it listens to and which devices play it. With device_id: play the stream on that device (needs `stream_url`); give host_device_id (a B&O device that is playing) so the stream carries its audio. With stop: stop it on that device.')]
class BeolinkStream extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->description('Device that should play (or stop) the stream'),
            'host_device_id' => $schema->integer()->description('B&O device whose playback the stream should carry'),
            'stop' => $schema->boolean()->description('Stop the stream on device_id instead of starting it'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'nullable|integer', 'host_device_id' => 'nullable|integer', 'stop' => 'nullable|boolean']);

        if (!isset($data['device_id'])) {
            return $this->api('GET', 'beolink-stream');
        }

        if ($data['stop'] ?? false) {
            return $this->api('DELETE', "devices/{$data['device_id']}/beolink-stream");
        }

        return $this->api('POST', "devices/{$data['device_id']}/beolink-stream", array_filter(['host_device_id' => $data['host_device_id'] ?? null]));
    }
}
