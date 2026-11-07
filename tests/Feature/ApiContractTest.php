<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\Scenes\OpenApiSpec;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakeRemoteDriver;
use Tests\Support\FakeSourceDriver;
use Tests\TestCase;

/**
 * The contract test of workstream 8b / 14.5 (docs/architecture/action-layer.md): docs/api/openapi.yaml, the
 * contract the iOS app and the HA package are built on, against the routes it describes.
 *
 * "Public API" is every route whose URI starts with `api/` (routes/api.php; the admin ones under `api/admin` too,
 * they are documented with `security`). Web pages, Livewire, `/mcp`, `/stream` and framework routes are not API.
 * Keys are "METHOD /path" with every `{parameter}` as `{}`; HEAD is ignored.
 *
 * tests/Support/openapi-known-missing.php lists the routes documented nowhere yet and the documented operations
 * served outside /api. The lists may only shrink: a listed entry that gets fixed fails the test until it is removed
 * from the list, and a new undocumented route fails it until it is documented.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    private static function key(string $method, string $path): string
    {
        return strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
    }

    /** @return array{0: array<string, true>, 1: array<string, true>} API routes, and every route of the app */
    private function routes(): array
    {
        $api = $all = [];
        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $uri = '/'.ltrim($route->uri(), '/');
                $all[self::key($method, $uri)] = true;
                if (str_starts_with($uri, '/api/')) {
                    $api[self::key($method, substr($uri, 4))] = true;
                }
            }
        }

        return [$api, $all];
    }

    /** @return array<string, string> "METHOD /path" => operationId ('' when missing) */
    private function documented(): array
    {
        $documented = [];
        foreach ($this->document()['paths'] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $documented[self::key($method, $path)] = $item[$method]['operationId'] ?? '';
                }
            }
        }

        return $documented;
    }

    private function document(): array
    {
        return Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    }

    private function known(): array
    {
        return require base_path('tests/Support/openapi-known-missing.php');
    }

    public function test_openapi_parses_with_a_strict_yaml_parser(): void
    {
        $document = $this->document();

        $this->assertSame('3.0.3', $document['openapi']);
        $this->assertNotEmpty($document['paths']);
        // The same file through the app's own loader (it parses strictly too).
        $this->assertSame($document, (new OpenApiSpec)->document());
    }

    public function test_every_public_api_route_is_documented_unless_known_missing(): void
    {
        [$routes] = $this->routes();
        $documented = $this->documented();
        $missing = $this->known()['missing'];

        $undocumented = array_values(array_diff(array_keys($routes), array_keys($documented), $missing));
        $this->assertSame([], $undocumented, 'New /api routes without an openapi.yaml operation (document them with an operationId): '.implode(', ', $undocumented));

        $nowDocumented = array_values(array_intersect($missing, array_keys($documented)));
        $this->assertSame([], $nowDocumented, 'Documented now, remove from tests/Support/openapi-known-missing.php: '.implode(', ', $nowDocumented));

        $noRoute = array_values(array_diff($missing, array_keys($routes)));
        $this->assertSame([], $noRoute, 'Listed as missing but not a route any more, remove from the list: '.implode(', ', $noRoute));
    }

    public function test_every_documented_operation_is_a_route_unless_known_stale(): void
    {
        [$routes, $all] = $this->routes();
        $stale = $this->known()['stale'];

        $unrouted = array_values(array_diff(array_keys($this->documented()), array_keys($routes), $stale));
        $this->assertSame([], $unrouted, 'Documented but no such /api route: '.implode(', ', $unrouted));

        $nowRouted = array_values(array_intersect($stale, array_keys($routes)));
        $this->assertSame([], $nowRouted, 'Routed under /api now, remove from the stale list: '.implode(', ', $nowRouted));

        // A stale entry must at least be served somewhere (outside /api), or it is simply wrong.
        $nowhere = array_values(array_diff($stale, array_keys($all)));
        $this->assertSame([], $nowhere, 'Documented, no route anywhere: fix openapi.yaml: '.implode(', ', $nowhere));
    }

    public function test_operation_ids_exist_are_unique_and_camel_case(): void
    {
        $ids = $this->documented();

        $this->assertSame([], array_keys(array_filter($ids, fn ($id) => $id === '')), 'Operations without an operationId');
        foreach ($ids as $key => $id) {
            $this->assertMatchesRegularExpression('/^[a-z][a-zA-Z0-9]*$/', $id, "operationId of {$key} is not camelCase");
        }
        $duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));
        $this->assertSame([], $duplicates, 'Duplicate operationIds: '.implode(', ', $duplicates));
    }

    public function test_the_known_lists_have_no_duplicates(): void
    {
        foreach ($this->known() as $name => $list) {
            $this->assertSame($list, array_values(array_unique($list)), "Duplicates in the {$name} list");
        }
    }

    /** One sample value for a body property, from the contract itself. */
    private function sample(array $schema): mixed
    {
        return $schema['example'] ?? $schema['enum'][0] ?? $schema['default'] ?? match ($schema['type'] ?? 'string') {
            'integer', 'number' => 1,
            'boolean' => true,
            'array' => [],
            'object' => [],
            default => 'x',
        };
    }

    /** Documented body properties reach validation: calling the route with the documented payload gives no 422 on a documented field. */
    public function test_documented_request_fields_are_accepted_by_the_routes(): void
    {
        FakePlayerDriver::reset();
        FakeRemoteDriver::reset();
        $spec = new OpenApiSpec;

        $from = Device::factory()->inState(State::Playing)->create();
        $target = Device::factory()->inState(State::Playing)->create();
        $remote = Device::factory()->driver(FakeRemoteDriver::class)->inState(State::Playing)->create();
        $source = Device::factory()->driver(FakeSourceDriver::class)->inState(State::Playing)->create();

        $cases = [
            'movePlayback' => [$from, ['to_device_id' => $target->id]],
            'activateSource' => [$source, []],
            'pressRemoteKey' => [$remote, []],
        ];

        foreach ($cases as $operationId => [$device, $override]) {
            $operation = $spec->operation($operationId);
            $properties = $spec->requestSchema($operationId)['properties'];
            $payload = $override + array_map(fn ($schema) => $this->sample($schema), $properties);

            $path = str_replace('{device}', (string) $device->id, '/api'.$operation['path']);
            $response = $this->json($operation['method'], $path, $payload);

            $this->assertNotSame(404, $response->getStatusCode(), "{$operationId}: {$path} is not routed");
            $response->assertJsonMissingValidationErrors(array_keys($properties));
        }
    }
}
