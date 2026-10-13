<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiBridge;
use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read or set volume (0-100) and mute of a device. Give `volume` and/or `muted` to set them; give neither to read both.')]
class Volume extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'volume' => $schema->integer()->min(0)->max(100),
            'muted' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
            'volume' => 'nullable|integer|min:0|max:100',
            'muted' => 'nullable|boolean',
        ]);
        $path = 'devices/'.$data['device_id'];
        $writes = array_filter([
            'volume' => isset($data['volume']) ? ['volume' => $data['volume']] : null,
            'mute' => isset($data['muted']) ? ['muted' => (bool) $data['muted']] : null,
        ]);

        // Write what was given, then answer with both values as the device now reports them.
        foreach ($writes as $part => $body) {
            $response = $this->api('PUT', "$path/$part", $body);
            if ($response->isError()) {
                return $response;
            }
        }

        $state = [];
        foreach (['volume', 'mute'] as $part) {
            [$status, $body] = app(ApiBridge::class)->call('GET', "$path/$part");
            if ($status >= 300) {
                return $this->api('GET', "$path/$part");
            }
            $state += $body;
        }

        return Response::json($state);
    }
}
