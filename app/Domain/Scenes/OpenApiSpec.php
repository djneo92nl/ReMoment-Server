<?php

namespace App\Domain\Scenes;

use Symfony\Component\Yaml\Yaml;

/**
 * docs/api/openapi.yaml as data: the machine-readable index of the REST API (docs/architecture/action-layer.md).
 *
 * Parsed once per file version (in-process, keyed by the file's mtime and size; no cache store involved, so a cache outage never breaks it), strictly:
 * a file that does not parse is an error, never a half-read contract. Operations are addressed by `operationId`.
 */
class OpenApiSpec
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var array<string, array> parsed documents by file key, so repeated calls cost nothing */
    private static array $memo = [];

    private array $document;

    /** @var array<string, array>|null operationId => operation entry */
    private ?array $operations = null;

    public function __construct(private readonly string $file = '')
    {
        $this->document = self::parse($file !== '' ? $file : base_path('docs/api/openapi.yaml'));
    }

    private static function parse(string $file): array
    {
        $key = 'openapi:'.md5($file).':'.@filemtime($file).':'.@filesize($file);

        return self::$memo[$key] ??= Yaml::parseFile($file);
    }

    public function document(): array
    {
        return $this->document;
    }

    /**
     * Every documented operation, keyed by operationId: `{id, method, path, operation, parameters}` where `parameters`
     * are the path-level and operation-level parameters with `$ref`s resolved (operation-level wins by name).
     *
     * @return array<string, array{id: string, method: string, path: string, operation: array, parameters: array}>
     */
    public function operations(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        $operations = [];
        foreach ($this->document['paths'] ?? [] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (!isset($item[$method]) || !is_array($item[$method])) {
                    continue;
                }

                $parameters = [];
                foreach (array_merge($item['parameters'] ?? [], $item[$method]['parameters'] ?? []) as $parameter) {
                    $parameter = $this->resolve($parameter);
                    $parameters[$parameter['in'].':'.$parameter['name']] = $parameter;
                }

                $id = $item[$method]['operationId'] ?? null;
                if ($id !== null) {
                    $operations[$id] = [
                        'id' => $id,
                        'method' => $method,
                        'path' => $path,
                        'operation' => $item[$method],
                        'parameters' => array_values($parameters),
                    ];
                }
            }
        }

        return $this->operations = $operations;
    }

    public function operation(string $operationId): ?array
    {
        return $this->operations()[$operationId] ?? null;
    }

    /**
     * Follow `$ref`s (`#/components/...`) in a node and in everything below it. Depth-limited so a recursive
     * schema cannot loop; deeper refs are left as they are.
     */
    public function resolve(mixed $node, int $depth = 8): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/') && $depth > 0) {
            $target = $this->document;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? null;
            }
            unset($node['$ref']);

            return $this->resolve(array_merge(is_array($target) ? $target : [], $node), $depth - 1);
        }

        return array_map(fn ($child) => $this->resolve($child, $depth), $node);
    }

    /** The JSON request body schema of an operation, refs resolved, or null when it takes none. */
    public function requestSchema(string $operationId): ?array
    {
        $operation = $this->operation($operationId);
        $schema = $operation['operation']['requestBody']['content']['application/json']['schema'] ?? null;

        return $schema === null ? null : $this->resolve($schema);
    }

    /**
     * What a caller supplies for an operation, flattened: path and query parameters and the properties of the JSON
     * body, by name. `in` is `path`, `query` or `body`.
     *
     * @return array<string, array{in: string, required: bool, schema: array, description: ?string}>
     */
    public function inputs(string $operationId): array
    {
        $operation = $this->operation($operationId);
        if ($operation === null) {
            return [];
        }

        $inputs = [];
        foreach ($operation['parameters'] as $parameter) {
            if (!in_array($parameter['in'], ['path', 'query'], true)) {
                continue;
            }
            $inputs[$parameter['name']] = [
                'in' => $parameter['in'],
                'required' => (bool) ($parameter['required'] ?? false) || $parameter['in'] === 'path',
                'schema' => $parameter['schema'] ?? [],
                'description' => $parameter['description'] ?? null,
            ];
        }

        $body = $this->requestSchema($operationId);
        foreach (($body['properties'] ?? []) as $name => $schema) {
            $inputs[$name] = [
                'in' => 'body',
                'required' => in_array($name, $body['required'] ?? [], true),
                'schema' => $schema,
                'description' => $schema['description'] ?? null,
            ];
        }

        return $inputs;
    }
}
