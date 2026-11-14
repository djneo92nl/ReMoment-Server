<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Scenes: household-wide lists of steps (an operationId of the REST API with device_id and params, or {type: "delay", seconds}) run in order. action: list, get (id), activate (id; runs now, a scene with delays is queued), create (name, steps; optional icon, color #rrggbb, stop_on_error, schedule_enabled, schedule_time HH:MM, schedule_days 1-7), update (id plus the fields to change), delete (id). Full shapes: docs/api/scenes.md.')]
class Scenes extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'activate', 'create', 'update', 'delete'])->required(),
            'id' => $schema->integer()->description('get, activate, update, delete: the scene id'),
            'name' => $schema->string(),
            'icon' => $schema->string(),
            'color' => $schema->string()->description('#rrggbb'),
            'steps' => $schema->array()->items($schema->object())->description('[{operation, device_id, params}] or [{type: "delay", seconds}]'),
            'stop_on_error' => $schema->boolean(),
            'schedule_enabled' => $schema->boolean(),
            'schedule_time' => $schema->string()->description('HH:MM, server time zone'),
            'schedule_days' => $schema->array()->items($schema->integer())->description('ISO weekdays 1 (Monday) to 7; empty = every day'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:list,get,activate,create,update,delete', 'id' => 'nullable|integer',
            'name' => 'nullable|string', 'icon' => 'nullable|string', 'color' => 'nullable|string', 'steps' => 'nullable|array',
            'stop_on_error' => 'nullable|boolean', 'schedule_enabled' => 'nullable|boolean', 'schedule_time' => 'nullable|string', 'schedule_days' => 'nullable|array',
        ]);

        $fields = $this->filled(array_intersect_key($data, array_flip(['name', 'icon', 'color', 'steps', 'stop_on_error', 'schedule_enabled', 'schedule_time', 'schedule_days'])));
        $id = $data['id'] ?? null;

        return match ($data['action']) {
            'list' => $this->api('GET', 'scenes'),
            'create' => $this->api('POST', 'scenes', $fields),
            default => $id === null
                ? Response::error("invalid (422): {$data['action']} needs an id.")
                : match ($data['action']) {
                    'get' => $this->api('GET', "scenes/{$id}"),
                    'activate' => $this->api('POST', "scenes/{$id}/activate"),
                    'update' => $this->api('PUT', "scenes/{$id}", $fields),
                    default => $this->api('DELETE', "scenes/{$id}"),
                },
        };
    }
}
