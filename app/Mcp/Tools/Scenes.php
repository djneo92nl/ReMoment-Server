<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Scenes: household-wide lists of steps (an operationId of the REST API with device_id and params, or {type: "delay", seconds}) run in order. action: list, get (id), activate (id; runs now, a scene with delays is queued), create (name, steps; optional icon, color #rrggbb, stop_on_error, schedule_enabled, schedule_time HH:MM, schedule_days 1-7, triggers [{type: "mqtt", topic, payload?} | {type: "device", device_id, event: "state"|"source", value}], trigger_debounce seconds, alarm {device_id, minutes, start_volume, target_volume}: ramps the volume up after the steps), update (id plus the fields to change; triggers [] or alarm null clears), delete (id). Sleep timer of a device (a server-side fade, then stop or standby, then the volume is put back; not the device\'s own sleep_timer): sleep (device_id, minutes; optional fade_seconds, end stop|standby), sleep_status (device_id), sleep_cancel (device_id). Full shapes: docs/api/scenes.md.')]
class Scenes extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'activate', 'create', 'update', 'delete', 'sleep', 'sleep_status', 'sleep_cancel'])->required(),
            'id' => $schema->integer()->description('get, activate, update, delete: the scene id'),
            'device_id' => $schema->integer()->description('sleep, sleep_status, sleep_cancel: the device'),
            'minutes' => $schema->integer()->description('sleep: 1-480'),
            'fade_seconds' => $schema->integer()->description('sleep: length of the fade at the end, 0-600 (default 60)'),
            'end' => $schema->string()->enum(['stop', 'standby'])->description('sleep: what happens at the end (default stop)'),
            'name' => $schema->string(),
            'icon' => $schema->string(),
            'color' => $schema->string()->description('#rrggbb'),
            'steps' => $schema->array()->items($schema->object())->description('[{operation, device_id, params}] or [{type: "delay", seconds}]'),
            'stop_on_error' => $schema->boolean(),
            'schedule_enabled' => $schema->boolean(),
            'schedule_time' => $schema->string()->description('HH:MM, server time zone'),
            'schedule_days' => $schema->array()->items($schema->integer())->description('ISO weekdays 1 (Monday) to 7; empty = every day'),
            'triggers' => $schema->array()->items($schema->object())->description('[{type: "mqtt", topic, payload?}] or [{type: "device", device_id, event: "state"|"source", value}]; max 10'),
            'trigger_debounce' => $schema->integer()->description('seconds a trigger start blocks the scene\'s other triggers, 5-3600 (default 10)'),
            'alarm' => $schema->object()->description('{device_id, minutes 1-60, start_volume, target_volume}; null clears'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:list,get,activate,create,update,delete,sleep,sleep_status,sleep_cancel', 'id' => 'nullable|integer',
            'device_id' => 'nullable|integer', 'minutes' => 'nullable|integer', 'fade_seconds' => 'nullable|integer', 'end' => 'nullable|in:stop,standby',
            'triggers' => 'nullable|array', 'trigger_debounce' => 'nullable|integer', 'alarm' => 'nullable|array',
            'name' => 'nullable|string', 'icon' => 'nullable|string', 'color' => 'nullable|string', 'steps' => 'nullable|array',
            'stop_on_error' => 'nullable|boolean', 'schedule_enabled' => 'nullable|boolean', 'schedule_time' => 'nullable|string', 'schedule_days' => 'nullable|array',
        ]);

        $fields = $this->filled(array_intersect_key($data, array_flip(['name', 'icon', 'color', 'steps', 'stop_on_error', 'schedule_enabled', 'schedule_time', 'schedule_days', 'trigger_debounce'])));
        // An empty list of triggers and a null alarm are real values (they clear), unlike an argument that was not given.
        foreach (['triggers', 'alarm'] as $key) {
            if (array_key_exists($key, $request->all())) {
                $fields[$key] = $data[$key] ?? null;
            }
        }
        $id = $data['id'] ?? null;
        $device = $data['device_id'] ?? null;

        if (in_array($data['action'], ['sleep', 'sleep_status', 'sleep_cancel'], true)) {
            if ($device === null) {
                return Response::error("invalid (422): {$data['action']} needs a device_id.");
            }

            return match ($data['action']) {
                'sleep' => ($data['minutes'] ?? null) === null
                    ? Response::error('invalid (422): sleep needs minutes.')
                    : $this->api('POST', "devices/{$device}/sleep", $this->filled(['minutes' => $data['minutes'], 'fade_seconds' => $data['fade_seconds'] ?? null, 'action' => $data['end'] ?? null])),
                'sleep_status' => $this->api('GET', "devices/{$device}/sleep"),
                default => $this->api('DELETE', "devices/{$device}/sleep"),
            };
        }

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
