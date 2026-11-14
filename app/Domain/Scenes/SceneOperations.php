<?php

namespace App\Domain\Scenes;

use Illuminate\Support\Str;

/**
 * The operations a scene step may call (config/scene-operations.php), resolved against docs/api/openapi.yaml.
 *
 * A step is `{ "operation": "movePlayback", "device_id": 3, "params": { "to_device_id": 5 } }`: an operation
 * reference that the (future) runner executes through the REST API. This class only knows which operations exist,
 * what they take, and whether a step is well-formed; the route's own validation stays the validator at run time.
 * Scenes themselves are not built (workstream 8.1).
 */
class SceneOperations
{
    public function __construct(private readonly ?OpenApiSpec $spec = null) {}

    private function spec(): OpenApiSpec
    {
        return $this->spec ?? app(OpenApiSpec::class);
    }

    /**
     * The operations a scene may call: every guest-level operation of the contract that changes something (not GET,
     * no `security`), minus `scene-operations.exclude`, with the overrides of `scene-operations.operations`
     * (label, device input, `enabled: false`): `operationId => {id, label, device_param, method, path, inputs}`.
     *
     * @return array<string, array>
     */
    public function all(bool $includeDisabled = false): array
    {
        $exclude = config('scene-operations.exclude', []);
        $overrides = config('scene-operations.operations', []);
        $result = [];

        foreach ($this->spec()->operations() as $id => $operation) {
            if ($operation['method'] === 'get' || isset($operation['operation']['security']) || in_array($id, $exclude, true)) {
                continue;
            }

            $inputs = $this->spec()->inputs($id);
            $entry = $overrides[$id] ?? [];
            $enabled = (bool) ($entry['enabled'] ?? true);
            if (!$includeDisabled && !$enabled) {
                continue;
            }

            $summary = trim((string) ($operation['operation']['summary'] ?? ''));
            $result[$id] = [
                'id' => $id,
                'label' => $entry['label'] ?? ($summary !== '' ? $summary : Str::headline($id)),
                'device_param' => array_key_exists('device_param', $entry)
                    ? $entry['device_param']
                    : (($inputs['device']['in'] ?? null) === 'path' ? 'device' : null),
                'enabled' => $enabled,
                'method' => $operation['method'],
                'path' => $operation['path'],
                'inputs' => $inputs,
            ];
        }

        return $result;
    }

    public function find(string $operationId): ?array
    {
        return $this->all()[$operationId] ?? null;
    }

    /**
     * Errors of a step against its operation's schema, as `field => message` (empty when valid). `operation` and
     * `device_id` are checked here; everything else is `params`, checked for required fields, types, enums, bounds
     * and unknown names. The device input (`device_param`) comes from `device_id`, not from `params`.
     *
     * @return array<string, string>
     */
    public function validateStep(array $step): array
    {
        $id = $step['operation'] ?? null;
        if (!is_string($id) || $id === '') {
            return ['operation' => 'An operation is required.'];
        }

        $operation = $this->find($id);
        if ($operation === null) {
            return ['operation' => "Unknown or disabled operation \"{$id}\"."];
        }

        $errors = [];
        $deviceParam = $operation['device_param'];

        if ($deviceParam !== null) {
            if (!isset($step['device_id'])) {
                $errors['device_id'] = 'A device is required.';
            } elseif (!is_int($step['device_id']) || $step['device_id'] < 1) {
                $errors['device_id'] = 'The device must be a device id.';
            }
        } elseif (isset($step['device_id'])) {
            $errors['device_id'] = 'This operation does not act on a device.';
        }

        $params = $step['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $errors + ['params' => 'Params must be an object.'];
        }

        foreach ($params as $name => $value) {
            if (!isset($operation['inputs'][$name])) {
                $errors["params.{$name}"] = 'Unknown parameter.';
            } elseif ($name === $deviceParam) {
                $errors["params.{$name}"] = 'Set the device with device_id.';
            }
        }

        foreach ($operation['inputs'] as $name => $input) {
            if ($name === $deviceParam) {
                continue;
            }
            if (!array_key_exists($name, $params)) {
                if ($input['required']) {
                    $errors["params.{$name}"] = 'Required.';
                }

                continue;
            }
            if ($error = $this->checkValue($params[$name], $input['schema'])) {
                $errors["params.{$name}"] = $error;
            }
        }

        return $errors;
    }

    /** One value against a (resolved) schema: type, enum, bounds, pattern, nested arrays. Null when fine. */
    private function checkValue(mixed $value, array $schema): ?string
    {
        if ($value === null) {
            return ($schema['nullable'] ?? false) ? null : 'May not be null.';
        }

        $type = $schema['type'] ?? null;
        $typeOk = match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => true,
        };
        if (!$typeOk) {
            return "Must be of type {$type}.";
        }

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return 'Must be one of: '.implode(', ', $schema['enum']).'.';
        }
        if (is_numeric($value) && !is_string($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return "Must be at least {$schema['minimum']}.";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return "Must be at most {$schema['maximum']}.";
            }
        }
        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                return "Must be at most {$schema['maxLength']} characters.";
            }
            if (isset($schema['pattern']) && !preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $value)) {
                return 'Has the wrong format.';
            }
        }
        if ($type === 'array') {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                return "Needs at least {$schema['minItems']} items.";
            }
            foreach ($value as $i => $item) {
                if (isset($schema['items']) && ($error = $this->checkValue($item, $schema['items']))) {
                    return "Item {$i}: {$error}";
                }
            }
        }

        return null;
    }
}
