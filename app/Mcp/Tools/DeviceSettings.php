<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read or change a device setting. setting: sound-adjustment (bass, treble, loudness), bluetooth (discoverable, reconnect_mode), info (name = rename), sleep-timer (minutes, 0 cancels). Without `values` it reads. Each needs the matching capability (sound_adjustment, bluetooth, device_info, sleep_timer); see docs/api/device-settings.md for shapes.')]
class DeviceSettings extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'setting' => $schema->string()->enum(['sound-adjustment', 'bluetooth', 'info', 'sleep-timer'])->required(),
            'values' => $schema->object()->description('Fields to set, e.g. {"bass": 2} or {"minutes": 30}; omit to read'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'setting' => 'required|in:sound-adjustment,bluetooth,info,sleep-timer', 'values' => 'nullable|array']);
        $path = 'devices/'.$data['device_id'].'/'.$data['setting'];

        return empty($data['values']) ? $this->api('GET', $path) : $this->api('PUT', $path, $data['values']);
    }
}
