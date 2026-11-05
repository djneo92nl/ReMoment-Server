<?php

namespace Tests\Feature\Actions;

use App\Domain\Actions\Actions;
use App\Domain\Actions\Generators;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Mcp\Tools;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeMovableDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/**
 * Spike 14.5 (docs/architecture/action-layer.md): what a single Action definition derives, checked against the
 * hand-written route, controller, MCP tool and openapi.yaml entry. Differences that were found are asserted too,
 * so the document's list stays true.
 */
class ActionLayerSpikeTest extends TestCase
{
    use RefreshDatabase;

    private array $openapi;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->openapi = $this->loadOpenApi();
        foreach (Actions::all() as $action) {
            Generators::registerRoute($action, '/api/_spike');
        }
    }

    /**
     * openapi.yaml does not parse as a whole with a strict YAML parser (see test_openapi_file_is_strict_yaml), so
     * the path items under test are cut out of the text and parsed one by one.
     */
    private function loadOpenApi(): array
    {
        $text = file_get_contents(base_path('docs/api/openapi.yaml'));
        $paths = [];

        foreach (['/devices/{device}/move', '/devices/{device}/sources/activate'] as $path) {
            preg_match('/^  '.preg_quote($path, '/').':\n(.*?)(?=^  \/|^components:)/ms', $text, $m);
            $paths[$path] = Yaml::parse(preg_replace('/^    /m', '', $m[1]));
        }

        return ['paths' => $paths];
    }

    public function test_openapi_file_is_strict_yaml(): void
    {
        try {
            Yaml::parseFile(base_path('docs/api/openapi.yaml'));
            $this->addToAssertionCount(1);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            $this->markTestIncomplete('Found by the 14.5 spike: docs/api/openapi.yaml is not valid YAML for strict parsers (PyYAML, Symfony): '.$e->getMessage());
        }
    }

    public static function actions(): array
    {
        return ['move_playback' => ['move_playback', '/devices/{device}/move'], 'activate_source' => ['activate_source', '/devices/{device}/sources/activate']];
    }

    #[DataProvider('actions')]
    public function test_openapi_operation_matches_the_hand_written_one(string $name, string $path): void
    {
        $derived = Generators::openApi(Actions::all()[$name])['post'];
        $real = $this->openapi['paths'][$path]['post'];

        $this->assertSame($real['operationId'], $derived['operationId']);
        $this->assertSame($real['summary'], $derived['summary']);
        $this->assertSame(
            $real['requestBody']['content']['application/json']['schema']['required'],
            $derived['requestBody']['content']['application/json']['schema']['required'],
        );
        $this->assertSame(
            $real['responses']['200']['content']['application/json']['schema']['properties'],
            $derived['responses']['200']['content']['application/json']['schema']['properties'],
        );
    }

    public function test_openapi_request_properties_match_except_what_a_rule_cannot_say(): void
    {
        $real = $this->openapi['paths']['/devices/{device}/move']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $derived = Generators::inputSchema(Actions::all()['move_playback'])['properties'];

        $this->assertEquals($real, $derived);
        $this->assertEquals(['source_id' => ['type' => 'string']], Generators::inputSchema(Actions::all()['activate_source'])['properties']);
    }

    public function test_openapi_differences_that_remain(): void
    {
        $real = $this->openapi['paths']['/devices/{device}/move']['post'];
        $derived = Generators::openApi(Actions::all()['move_playback'])['post'];

        // The hand-written description is longer prose, and the real file shares the error responses differently.
        $this->assertNotSame($real['description'], $derived['description']);
        $this->assertSame(['200', '422', '503'], array_map('strval', array_keys($real['responses'])));
        $this->assertSame(['200', '422', '502', '503'], array_map('strval', array_keys($derived['responses'])));
        // The path-parameter block and tags are not part of an action definition.
        $this->assertArrayNotHasKey('tags', $derived);
    }

    #[DataProvider('actions')]
    public function test_mcp_schema_matches_the_hand_written_tool(string $name): void
    {
        $tool = ['move_playback' => new Tools\MovePlayback, 'activate_source' => new Tools\Sources][$name];
        $factory = new JsonSchemaTypeFactory;

        $derived = $this->serialize(Generators::mcpSchema(Actions::all()[$name], $factory));
        $real = $this->serialize($tool->schema($factory));

        if ($name === 'move_playback') {
            // Only the device_id wording differs: the tool says "the device that plays now", the generic one cannot.
            $this->assertNotSame($real['device_id']['description'], $derived['device_id']['description']);
            unset($real['device_id']['description'], $derived['device_id']['description']);
            $this->assertSame($real, $derived, 'same fields, types, required flags and descriptions');
        } else {
            // `sources` is one tool for list/activate/controls, so it is a different shape by design.
            $this->assertSame('integer', $derived['device_id']['type']);
            $this->assertArrayHasKey('activate', $real);
            $this->assertArrayHasKey('source_id', $derived);
        }
    }

    private function serialize(array $fields): array
    {
        return collect($fields)->map(fn ($f) => $f->toArray())->all();
    }

    public function test_scene_step_and_ha_snippet(): void
    {
        $step = Generators::sceneStep(Actions::all()['move_playback']);
        $this->assertSame(['action', 'device_id', 'to_device_id'], $step['required']);
        $this->assertSame('move_playback', $step['properties']['action']['const']);

        $ha = Generators::haRestCommand(Actions::all()['move_playback']);
        $this->assertStringContainsString('url: "http://remoment.local/api/devices/{{ device_id }}/move"', $ha);
        $this->assertStringContainsString('dict(to_device_id=to_device_id)', $ha);
    }

    /** The derived route answers like the real controller for the same input. */
    public function test_move_route_has_the_same_answers_as_the_controller(): void
    {
        $from = Device::factory()->inState(State::Playing)->create();
        $to = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create();
        DeviceCache::updateNowPlaying($from->id, new NowPlaying(state: 'playing', source: new Source(name: 'TV', sourceType: 'HDMI')));
        $gone = Device::factory()->inState(State::Unreachable)->create();

        $cases = [
            'not movable' => [$from, ['to_device_id' => $to->id]],
            'missing target' => [$from, []],
            'bad boolean' => [$from, ['to_device_id' => $to->id, 'stop_source' => 'maybe']],
            'unknown target' => [$from, ['to_device_id' => 9999]],
            'target unreachable' => [$from, ['to_device_id' => $gone->id]],
        ];

        foreach ($cases as $label => [$device, $body]) {
            $real = $this->postJson("/api/devices/{$device->id}/move", $body);
            $derived = $this->postJson("/api/_spike/devices/{$device->id}/move", $body);
            $this->assertSame($real->status(), $derived->status(), $label);
            $this->assertSame($real->json(), $derived->json(), $label);
        }
    }

    public function test_move_route_difference_moving_onto_itself(): void
    {
        $device = Device::factory()->inState(State::Playing)->create();
        $body = ['to_device_id' => $device->id];

        $real = $this->postJson("/api/devices/{$device->id}/move", $body);
        $derived = $this->postJson("/api/_spike/devices/{$device->id}/move", $body);

        // Both refuse with 422, but the controller expresses it as a validation rule (Rule::notIn with the route's
        // device), which a rules() without access to the route device cannot: the body differs.
        $this->assertSame(422, $real->status());
        $this->assertSame(422, $derived->status());
        $this->assertArrayHasKey('errors', $real->json());
        $this->assertSame('invalid', $derived->json('error'));
    }

    public function test_activate_source_route_has_the_same_answers_as_the_controller(): void
    {
        $device = Device::factory()->inState(State::Playing)->create();
        $gone = Device::factory()->inState(State::Unreachable)->create();
        $noSources = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create();

        $cases = [
            'ok' => [$device, ['source_id' => 'MUSIC']],
            'missing source' => [$device, []],
            'unreachable devices are tried anyway' => [$gone, ['source_id' => 'MUSIC']],
            'no capability' => [$noSources, ['source_id' => 'MUSIC']],
        ];

        foreach ($cases as $label => [$d, $body]) {
            $real = $this->postJson("/api/devices/{$d->id}/sources/activate", $body);
            $derived = $this->postJson("/api/_spike/devices/{$d->id}/sources/activate", $body);
            $this->assertSame($real->status(), $derived->status(), $label);
            $this->assertSame($real->json(), $derived->json(), $label);
        }
    }
}
