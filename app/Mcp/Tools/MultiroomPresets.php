<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Multiroom presets: named groups of devices, the first one the host (docs/api/multiroom-presets.md). Open like multiroom itself. action: list, create (name, device_ids: at least 2), update (id; name and/or device_ids), delete (id), activate (id: every other device joins the host; answers who joined and who failed). The per-device join/leave is the `multiroom` tool.')]
class MultiroomPresets extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'create', 'update', 'delete', 'activate'])->required(),
            'id' => $schema->integer()->description('update, delete, activate'),
            'name' => $schema->string()->description('create, update'),
            'device_ids' => $schema->array()->items($schema->integer())->description('create, update: at least 2, the first is the host'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['action' => 'required|in:list,create,update,delete,activate', 'id' => 'nullable|integer', 'name' => 'nullable|string', 'device_ids' => 'nullable|array', 'device_ids.*' => 'integer']);

        if (in_array($data['action'], ['update', 'delete', 'activate'], true) && !isset($data['id'])) {
            return Response::error("invalid (422): {$data['action']} needs id.");
        }

        $body = $this->filled(['name' => $data['name'] ?? null, 'device_ids' => $data['device_ids'] ?? null]);

        return match ($data['action']) {
            'create' => $this->api('POST', 'multiroom/presets', $body),
            'update' => $this->api('PUT', "multiroom/presets/{$data['id']}", $body),
            'delete' => $this->api('DELETE', "multiroom/presets/{$data['id']}"),
            'activate' => $this->api('POST', "multiroom/presets/{$data['id']}/activate"),
            default => $this->api('GET', 'multiroom/presets'),
        };
    }
}
