<?php

namespace Tests\Unit\Scenes;

use App\Domain\Scenes\OpenApiSpec;
use App\Domain\Scenes\SceneOperations;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The curated scene-capable operations (config/scene-operations.php) and the step validator. */
class SceneOperationsTest extends TestCase
{
    private function ops(): SceneOperations
    {
        return new SceneOperations(new OpenApiSpec);
    }

    public function test_every_override_and_exclusion_names_a_real_operation_and_a_real_device_input(): void
    {
        $spec = new OpenApiSpec;
        foreach ([...array_keys(config('scene-operations.operations')), ...config('scene-operations.exclude')] as $id) {
            $this->assertNotNull($spec->operation($id), "{$id} is not in docs/api/openapi.yaml");
        }

        foreach ($this->ops()->all(includeDisabled: true) as $id => $operation) {
            if ($operation['device_param'] !== null) {
                $this->assertArrayHasKey($operation['device_param'], $operation['inputs'], "{$id}: device_param is not an input");
            }
            $this->assertNotSame('', $operation['label']);
        }
    }

    public function test_every_guest_level_mutating_operation_is_available_except_the_excluded(): void
    {
        $spec = new OpenApiSpec;
        $expected = [];
        foreach ($spec->operations() as $id => $operation) {
            if ($operation['method'] !== 'get' && !isset($operation['operation']['security']) && !in_array($id, config('scene-operations.exclude'), true)) {
                $expected[] = $id;
            }
        }

        $this->assertEqualsCanonicalizing($expected, array_keys($this->ops()->all()));
        $this->assertArrayHasKey('wantTrack', $this->ops()->all());
        $this->assertArrayNotHasKey('adminLogin', $this->ops()->all());
        $this->assertArrayNotHasKey('registerClient', $this->ops()->all());
    }

    public function test_no_available_operation_needs_the_admin_token(): void
    {
        $spec = new OpenApiSpec;
        $adminPaths = [];
        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (in_array('admin.token', $route->gatherMiddleware(), true) || in_array(\App\Http\Middleware\AuthenticateAdminToken::class, $route->gatherMiddleware(), true)) {
                foreach ($route->methods() as $method) {
                    $adminPaths[strtolower($method).' '.preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($route->uri(), '/'))] = true;
                }
            }
        }

        foreach (array_keys($this->ops()->all(includeDisabled: true)) as $id) {
            $operation = $spec->operation($id);
            $this->assertArrayNotHasKey('security', $operation['operation'], "{$id} is marked admin in openapi.yaml");
            $key = $operation['method'].' /api'.preg_replace('/\{[^}]+\}/', '{}', $operation['path']);
            $this->assertArrayNotHasKey($key, $adminPaths, "{$id} is behind admin.token");
        }
    }

    public function test_a_valid_step_has_no_errors(): void
    {
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'movePlayback', 'device_id' => 3, 'params' => ['to_device_id' => 5]]));
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'movePlayback', 'device_id' => 3, 'params' => ['to_device_id' => 5, 'stop_source' => false]]));
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'pressRemoteKey', 'device_id' => 3, 'params' => ['key' => 'ok']]));
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'transport', 'device_id' => 3, 'params' => ['action' => 'pause']]));
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'clearQueue', 'device_id' => 3]));
    }

    public function test_an_unknown_missing_or_admin_operation_is_refused(): void
    {
        $this->assertArrayHasKey('operation', $this->ops()->validateStep(['operation' => 'noSuchThing', 'device_id' => 1]));
        $this->assertArrayHasKey('operation', $this->ops()->validateStep(['device_id' => 1]));
        // In the contract but not in the list (and admin-only): a scene may not call it.
        $this->assertArrayHasKey('operation', $this->ops()->validateStep(['operation' => 'adminLogout']));
        $this->assertArrayHasKey('operation', $this->ops()->validateStep(['operation' => 'listDevices']));
    }

    public function test_a_disabled_operation_is_refused(): void
    {
        config(['scene-operations.operations.setMute.enabled' => false]);

        $this->assertArrayHasKey('operation', $this->ops()->validateStep(['operation' => 'setMute', 'device_id' => 1, 'params' => ['muted' => true]]));
        $this->assertArrayNotHasKey('setMute', $this->ops()->all());
        $this->assertArrayHasKey('setMute', $this->ops()->all(includeDisabled: true));
    }

    public function test_a_missing_required_field_is_an_error(): void
    {
        $errors = $this->ops()->validateStep(['operation' => 'movePlayback', 'device_id' => 3]);
        $this->assertSame(['params.to_device_id' => 'Required.'], $errors);

        // A required path parameter other than the device counts too.
        $this->assertArrayHasKey('params.action', $this->ops()->validateStep(['operation' => 'transport', 'device_id' => 3]));
    }

    public function test_a_wrong_type_is_an_error(): void
    {
        $errors = $this->ops()->validateStep(['operation' => 'movePlayback', 'device_id' => 3, 'params' => ['to_device_id' => '5', 'stop_source' => 'yes']]);

        $this->assertArrayHasKey('params.to_device_id', $errors);
        $this->assertArrayHasKey('params.stop_source', $errors);
        $this->assertArrayHasKey('params.volume', $this->ops()->validateStep(['operation' => 'setVolume', 'device_id' => 3, 'params' => ['volume' => 'loud']]));
        $this->assertArrayHasKey('params', $this->ops()->validateStep(['operation' => 'movePlayback', 'device_id' => 3, 'params' => [5]]));
    }

    public function test_an_enum_bound_and_pattern_are_checked(): void
    {
        $this->assertArrayHasKey('params.key', $this->ops()->validateStep(['operation' => 'pressRemoteKey', 'device_id' => 3, 'params' => ['key' => 'banana']]));
        $this->assertArrayHasKey('params.action', $this->ops()->validateStep(['operation' => 'transport', 'device_id' => 3, 'params' => ['action' => 'explode']]));
        $this->assertArrayHasKey('params.digits', $this->ops()->validateStep(['operation' => 'sendDigits', 'device_id' => 3, 'params' => ['digits' => '12ab']]));
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'sendDigits', 'device_id' => 3, 'params' => ['digits' => '105']]));
        $this->assertArrayHasKey('params.volume', $this->ops()->validateStep(['operation' => 'setVolume', 'device_id' => 3, 'params' => ['volume' => 101]]));
    }

    public function test_the_device_comes_from_device_id_not_params(): void
    {
        $this->assertSame(['device_id' => 'A device is required.'], $this->ops()->validateStep(['operation' => 'clearQueue']));
        $this->assertArrayHasKey('device_id', $this->ops()->validateStep(['operation' => 'clearQueue', 'device_id' => '3']));
        $this->assertArrayHasKey('params.device', $this->ops()->validateStep(['operation' => 'clearQueue', 'device_id' => 3, 'params' => ['device' => 4]]));
        $this->assertArrayHasKey('params.nonsense', $this->ops()->validateStep(['operation' => 'clearQueue', 'device_id' => 3, 'params' => ['nonsense' => 1]]));

        // A body property as the device (sync group leader) and an operation with no device at all.
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'createSyncGroup', 'device_id' => 1, 'params' => ['member_device_ids' => [2, 3]]]));
        $this->assertArrayHasKey('params.member_device_ids', $this->ops()->validateStep(['operation' => 'createSyncGroup', 'device_id' => 1, 'params' => ['member_device_ids' => []]]));
        $this->assertSame([], $this->ops()->validateStep(['operation' => 'deleteSyncGroup', 'params' => ['group' => 4]]));
        $this->assertArrayHasKey('device_id', $this->ops()->validateStep(['operation' => 'deleteSyncGroup', 'device_id' => 1, 'params' => ['group' => 4]]));
    }

    public function test_refs_are_resolved_for_the_request_schema(): void
    {
        $spec = new OpenApiSpec;

        // pressRemoteKey's `key` is a $ref to the RemoteKey enum component.
        $this->assertContains('ok', $spec->inputs('pressRemoteKey')['key']['schema']['enum']);
        $this->assertSame('path', $spec->inputs('movePlayback')['device']['in']);
        $this->assertTrue($spec->inputs('movePlayback')['to_device_id']['required']);
        $this->assertFalse($spec->inputs('movePlayback')['stop_source']['required']);
        $this->assertNull($spec->requestSchema('clearQueue'));
    }
}
