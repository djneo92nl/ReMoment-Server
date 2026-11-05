<?php

namespace App\Domain\Actions;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Route;

/** Derives the artefacts of an action from its definition (spike: see docs/architecture/action-layer.md). */
final class Generators
{
    /** Laravel validation rules → OpenAPI property schemas + required list (string/integer/boolean/in only). */
    public static function inputSchema(Action $action): array
    {
        $properties = [];
        $required = [];

        foreach ($action->rules() as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', $rules);
            $rules = array_values(array_filter($rules, 'is_string'));
            $type = match (true) {
                in_array('integer', $rules, true) => 'integer',
                in_array('boolean', $rules, true) => 'boolean',
                in_array('numeric', $rules, true) => 'number',
                default => 'string',
            };
            $schema = ['type' => $type];
            if (array_key_exists($field, $action->defaults())) {
                $schema['default'] = $action->defaults()[$field];
            }
            $properties[$field] = $schema;
            if (in_array('required', $rules, true)) {
                $required[] = $field;
            }
        }

        return ['type' => 'object', 'required' => $required, 'properties' => $properties];
    }

    /** The OpenAPI path item fragment (method → operation), without the path parameter block. */
    public static function openApi(Action $action): array
    {
        $responses = ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $action->outputs()]]]]];
        $statuses = array_unique(array_merge($action->errors() ? array_values($action->errors()) : [], $action->capability() ? [422] : []));
        sort($statuses);
        foreach ($statuses as $status) {
            $responses[(string) $status] = ['$ref' => '#/components/responses/DeviceError'];
        }

        $operation = [
            'summary' => $action->summary(),
            'description' => $action->description(),
            'operationId' => $action->operationId(),
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => self::inputSchema($action)]]],
            'responses' => $responses,
        ];

        if ($action->adminOnly()) {
            $operation['security'] = [['adminToken' => []]];
        }

        return [$action->method() => $operation];
    }

    /** The MCP tool's input schema (the same shape `Tool::schema()` returns): `device_id` plus the body fields. */
    public static function mcpSchema(Action $action, JsonSchema $schema): array
    {
        $fields = ['device_id' => $schema->integer()->description('the device the action is about')->required()];
        $input = self::inputSchema($action);

        foreach ($input['properties'] as $name => $def) {
            $type = match ($def['type']) {
                'integer' => $schema->integer(),
                'boolean' => $schema->boolean(),
                'number' => $schema->number(),
                default => $schema->string(),
            };
            if ($description = $action->inputDescriptions()[$name] ?? null) {
                $type = $type->description($description);
            }
            $fields[$name] = in_array($name, $input['required'], true) ? $type->required() : $type;
        }

        return $fields;
    }

    /** A scene step: JSON Schema of one `{action, device_id, …}` object; the scene editor and the activator validate against it. */
    public static function sceneStep(Action $action): array
    {
        $input = self::inputSchema($action);

        return [
            'type' => 'object',
            'required' => array_merge(['action', 'device_id'], $input['required']),
            'properties' => ['action' => ['const' => $action->name()], 'device_id' => ['type' => 'integer']] + $input['properties'],
            'additionalProperties' => false,
        ];
    }

    /** A Home Assistant `rest_command` entry (YAML text). *Unverified in a running HA.* */
    public static function haRestCommand(Action $action, string $baseUrl = 'http://remoment.local'): string
    {
        $input = self::inputSchema($action);
        $url = $baseUrl.'/api'.str_replace('{device}', '{{ device_id }}', $action->path());
        $required = array_filter(array_map(fn ($f) => in_array($f, $input['required'], true) ? "{$f}=$f" : null, array_keys($input['properties'])));

        return "remoment_{$action->name()}:\n"
            ."  url: \"$url\"\n"
            ."  method: {$action->method()}\n"
            ."  content_type: \"application/json\"\n"
            ."  payload: '{{ dict(".implode(', ', $required).") | tojson }}'\n";
    }

    /** Registers the action's route on the given path prefix (the spike's tests use a throwaway prefix; real routes stay as they are). */
    public static function registerRoute(Action $action, string $prefix = '/api'): void
    {
        Route::{$action->method()}($prefix.$action->path(), fn (\Illuminate\Http\Request $request, \App\Models\Device $device) => app(ActionRunner::class)->run($action, $device, $request->all()))
            ->middleware($action->adminOnly() ? ['api', 'admin.token'] : ['api']);
    }
}
