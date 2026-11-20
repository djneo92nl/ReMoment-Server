<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Full detail of one device: what is playing now (track, album, radio, source, position, artwork, shuffle/repeat/liked modes), capabilities, volume-related state, multiroom, and the sub device relation (host: the device this one is connected to; sub_devices: the devices connected to it, with `active` while the host has their input selected).')]
#[IsReadOnly]
class GetDevice extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return ['device_id' => $schema->integer()->description('Device id from list_devices')->required()];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['device_id' => 'required|integer']);

        return $this->api('GET', 'devices/'.$request->integer('device_id'));
    }
}
