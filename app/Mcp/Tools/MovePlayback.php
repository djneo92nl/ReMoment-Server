<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Move what a device plays to another device ("move playback to…"): library tracks continue at the same position with the same queue, radio restarts the station, Spotify is handed over with Spotify\'s own transfer (only to a speaker mapped to a Spotify Connect name). The playing device is stopped afterwards unless stop_source is false. Inputs of the device itself (line-in, TV, CD, Bluetooth, AirPlay/Cast) cannot be moved: not_movable (422). Check the `move_playback` capability on the source device first. Errors: not_movable, not_playable, unreachable, driver_error.')]
class MovePlayback extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->description('the device that plays now')->required(),
            'to_device_id' => $schema->integer()->description('the device to continue on')->required(),
            'stop_source' => $schema->boolean()->description('stop the source device afterwards (default true)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'to_device_id' => 'required|integer', 'stop_source' => 'nullable|boolean']);

        return $this->api('POST', 'devices/'.$data['device_id'].'/move', array_filter([
            'to_device_id' => $data['to_device_id'],
            'stop_source' => $data['stop_source'] ?? null,
        ], fn ($v) => $v !== null));
    }
}
