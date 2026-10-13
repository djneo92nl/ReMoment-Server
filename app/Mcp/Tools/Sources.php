<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Input sources of a device (Spotify, line-in, TV, CD…). Omit `activate` to list the visible sources (the selected one has active=true); give a source_id to switch to it. For sources with their own controls (CD disc change, FM presets) use `control` (id from `list_controls`).')]
class Sources extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'activate' => $schema->string()->description('source_id to switch to'),
            'list_controls' => $schema->boolean()->description('List the controls of the playing source (CD, tape, FM presets)'),
            'control' => $schema->string()->description('Control id to run, e.g. next_disc'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['device_id' => 'required|integer', 'activate' => 'nullable|string', 'list_controls' => 'nullable|boolean', 'control' => 'nullable|string']);
        $path = 'devices/'.$data['device_id'];

        return match (true) {
            isset($data['control']) => $this->api('POST', "$path/controls/{$data['control']}"),
            !empty($data['list_controls']) => $this->api('GET', "$path/controls"),
            isset($data['activate']) => $this->api('POST', "$path/sources/activate", ['source_id' => $data['activate']]),
            default => $this->api('GET', "$path/sources", ['visible' => 1]),
        };
    }
}
