<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\Scenes\SceneOperations;
use App\Domain\Scenes\SceneRunner;
use App\Jobs\RunScene;
use App\Mcp\ApiBridge;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** Scenes (workstream 8.1, docs/api/scenes.md): validation, the runner, the API, the schedule and the MCP tool. */
class SceneTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->inState(State::Playing)->create(['device_name' => 'Kitchen']);
    }

    private function volume(int $level, ?int $device = null): array
    {
        return ['operation' => 'setVolume', 'device_id' => $device ?? $this->device->id, 'params' => ['volume' => $level]];
    }

    private function scene(array $steps, array $attributes = []): Scene
    {
        return Scene::create(['name' => 'Evening', 'steps' => $steps] + $attributes);
    }

    /** A bridge that records its calls and answers from a script of statuses. */
    private function fakeBridge(array $statuses = []): ApiBridge
    {
        return new class($statuses) extends ApiBridge
        {
            public array $calls = [];

            public function __construct(private array $statuses)
            {
                parent::__construct(app('router'));
            }

            public function call(string $method, string $path, array $params = []): array
            {
                $this->calls[] = [$method, $path, $params];
                $status = array_shift($this->statuses) ?? 200;

                return [$status, $status < 300 ? ['status' => 'ok'] : ['error' => 'unreachable', 'message' => 'Device is not reachable.']];
            }
        };
    }

    private function runner(ApiBridge $bridge, array &$slept = []): SceneRunner
    {
        return new SceneRunner($bridge, app(SceneOperations::class), function ($s) use (&$slept) {
            $slept[] = $s;
        });
    }

    // Validation

    public function test_validate_reports_every_bad_step(): void
    {
        $errors = $this->runner($this->fakeBridge())->validate([
            $this->volume(30),
            ['operation' => 'noSuchThing'],
            ['operation' => 'setVolume', 'device_id' => 1, 'params' => ['volume' => 500]],
            ['type' => 'delay', 'seconds' => 999],
        ]);

        $this->assertArrayNotHasKey('steps.0.operation', $errors);
        $this->assertArrayHasKey('steps.1.operation', $errors);
        $this->assertArrayHasKey('steps.2.params.volume', $errors);
        $this->assertArrayHasKey('steps.3.seconds', $errors);
        $this->assertArrayHasKey('steps', $this->runner($this->fakeBridge())->validate([]));
    }

    public function test_delays_add_up_to_a_cap(): void
    {
        $steps = array_fill(0, 6, ['type' => 'delay', 'seconds' => 60]);

        $this->assertArrayHasKey('steps', $this->runner($this->fakeBridge())->validate($steps));
    }

    public function test_a_scene_cannot_run_scenes(): void
    {
        $this->assertNull(app(SceneOperations::class)->find('activateScene'));
        $this->assertNull(app(SceneOperations::class)->find('createScene'));
    }

    // Runner

    public function test_runs_steps_in_order_through_the_bridge_with_the_device_filled_in(): void
    {
        $bridge = $this->fakeBridge();
        $scene = $this->scene([$this->volume(20), ['operation' => 'transport', 'device_id' => $this->device->id, 'params' => ['action' => 'play']]]);

        $out = $this->runner($bridge)->run($scene, 'api');

        $this->assertSame('ok', $out['status']);
        $this->assertSame(['put', "/devices/{$this->device->id}/volume", ['volume' => 20]], $bridge->calls[0]);
        $this->assertSame('post', $bridge->calls[1][0]);
        $this->assertStringStartsWith("/devices/{$this->device->id}/", $bridge->calls[1][1]);
        $scene->refresh();
        $this->assertSame('ok', $scene->last_run_status);
        $this->assertSame('api', $scene->last_run_source);
        $this->assertCount(2, $scene->last_run_results);
    }

    public function test_continues_after_an_error_by_default(): void
    {
        $bridge = $this->fakeBridge([503, 200]);
        $out = $this->runner($bridge)->execute([$this->volume(10), $this->volume(20)], false);

        $this->assertSame('partial', $out['status']);
        $this->assertCount(2, $bridge->calls);
        $this->assertFalse($out['results'][0]['ok']);
        $this->assertSame(503, $out['results'][0]['status']);
        $this->assertStringContainsString('unreachable', $out['results'][0]['error']);
        $this->assertTrue($out['results'][1]['ok']);
    }

    public function test_stop_on_error_skips_the_rest(): void
    {
        $bridge = $this->fakeBridge([503]);
        $out = $this->runner($bridge)->execute([$this->volume(10), $this->volume(20), $this->volume(30)], true);

        $this->assertSame('failed', $out['status']);
        $this->assertCount(1, $bridge->calls);
        $this->assertTrue($out['results'][1]['skipped']);
        $this->assertTrue($out['results'][2]['skipped']);
    }

    public function test_delay_steps_wait_and_are_reported(): void
    {
        $slept = [];
        $out = $this->runner($this->fakeBridge(), $slept)->execute([$this->volume(10), ['type' => 'delay', 'seconds' => 7], $this->volume(20)], false);

        $this->assertSame([7], $slept);
        $this->assertSame('delay', $out['results'][1]['type']);
        $this->assertSame('ok', $out['status']);
    }

    public function test_an_invalid_stored_scene_runs_nothing(): void
    {
        $bridge = $this->fakeBridge();
        $out = $this->runner($bridge)->execute([$this->volume(10), ['operation' => 'gone']], false);

        $this->assertSame('failed', $out['status']);
        $this->assertSame([], $bridge->calls);
    }

    public function test_runs_against_the_real_api(): void
    {
        $scene = $this->scene([$this->volume(44)]);

        $out = app(SceneRunner::class)->run($scene);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(44, FakePlayerDriver::$volume);
    }

    // API

    public function test_create_list_show_update_delete(): void
    {
        $id = $this->postJson('/api/scenes', ['name' => 'Evening', 'icon' => 'moon', 'color' => '#336699', 'steps' => [$this->volume(25)]])
            ->assertCreated()->assertJsonPath('data.name', 'Evening')->assertJsonPath('data.step_count', 1)->json('data.id');

        $this->getJson('/api/scenes')->assertOk()->assertJsonPath('data.0.icon', 'moon')->assertJsonPath('data.0.color', '#336699')->assertJsonMissingPath('data.0.steps');
        $this->getJson("/api/scenes/{$id}")->assertOk()->assertJsonPath('data.steps.0.operation', 'setVolume');

        $this->putJson("/api/scenes/{$id}", ['name' => 'Night'])->assertOk()->assertJsonPath('data.name', 'Night')->assertJsonPath('data.step_count', 1);
        $this->deleteJson("/api/scenes/{$id}")->assertOk();
        $this->getJson("/api/scenes/{$id}")->assertNotFound();
    }

    public function test_saving_refuses_bad_steps_with_field_errors(): void
    {
        $this->postJson('/api/scenes', ['name' => 'Bad', 'steps' => [['operation' => 'setVolume', 'device_id' => $this->device->id]]])
            ->assertStatus(422)->assertJsonValidationErrors(['steps.0.params.volume']);
        $this->postJson('/api/scenes', ['name' => 'Bad', 'color' => 'red', 'steps' => [$this->volume(1)]])->assertStatus(422)->assertJsonValidationErrors(['color']);
        $this->postJson('/api/scenes', ['name' => 'Bad', 'steps' => [$this->volume(1)], 'schedule_enabled' => true])->assertStatus(422)->assertJsonValidationErrors(['schedule_time']);
        $this->assertSame(0, Scene::count());
    }

    public function test_activate_runs_inline_and_answers_per_step(): void
    {
        $scene = $this->scene([$this->volume(33), $this->volume(5, 9999)]);

        $this->postJson("/api/scenes/{$scene->id}/activate")->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', false)
            ->assertJsonPath('results.1.status', 404)
            ->assertJsonPath('data.last_run.status', 'partial');
        $this->assertSame(33, FakePlayerDriver::$volume);
    }

    public function test_activate_queues_a_scene_with_delays(): void
    {
        Queue::fake();
        $scene = $this->scene([$this->volume(33), ['type' => 'delay', 'seconds' => 5]]);

        $this->postJson("/api/scenes/{$scene->id}/activate")->assertStatus(202)
            ->assertJsonPath('status', 'queued')->assertJsonPath('data.last_run.status', 'running');
        Queue::assertPushed(RunScene::class, fn ($j) => $j->sceneId === $scene->id);
    }

    public function test_the_job_runs_the_scene(): void
    {
        $scene = $this->scene([$this->volume(12)]);

        (new RunScene($scene->id, 'schedule'))->handle(app(SceneRunner::class));

        $this->assertSame(12, FakePlayerDriver::$volume);
        $this->assertSame('schedule', $scene->fresh()->last_run_source);
    }

    public function test_info_lists_the_feature_in_every_profile(): void
    {
        foreach (['control', 'standard', 'full'] as $profile) {
            config(['profile.current' => $profile]);
            $this->getJson('/api/info')->assertJsonPath('features.scenes', true);
        }
    }

    // Schedule

    public function test_due_scenes_match_time_and_weekday_and_run_once_a_minute(): void
    {
        Bus::fake();
        $monday = now()->setDate(2026, 11, 16)->setTime(7, 30, 10); // a Monday
        $due = $this->scene([$this->volume(1)], ['schedule_enabled' => true, 'schedule_time' => '07:30', 'schedule_days' => [1, 3]]);
        $this->scene([$this->volume(1)], ['schedule_enabled' => true, 'schedule_time' => '07:30', 'schedule_days' => [2]]);   // wrong day
        $this->scene([$this->volume(1)], ['schedule_enabled' => true, 'schedule_time' => '07:31']);                           // wrong minute
        $this->scene([$this->volume(1)], ['schedule_enabled' => false, 'schedule_time' => '07:30']);                          // off
        $every = $this->scene([$this->volume(1)], ['schedule_enabled' => true, 'schedule_time' => '07:30']);                  // every day

        $this->travelTo($monday);
        $runner = app(SceneRunner::class);
        $this->assertSame(2, $runner->runDue($monday));
        Bus::assertDispatched(RunScene::class, fn ($j) => $j->sceneId === $due->id && $j->source === 'schedule');
        Bus::assertDispatched(RunScene::class, fn ($j) => $j->sceneId === $every->id);

        // Marked running at 07:30:10 now: the same minute does not queue it again.
        $this->travelTo($monday->copy()->setTime(7, 30, 40));
        $this->assertSame(0, $runner->runDue(now()));
        $this->travelBack();
    }

    // MCP

    public function test_mcp_tool_create_list_activate(): void
    {
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'create', 'name' => 'Evening', 'steps' => [$this->volume(18)]])->assertOk()->assertSee('Evening');
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'list'])->assertOk()->assertSee('Evening');
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'activate', 'id' => Scene::first()->id])->assertOk()->assertSee('"status":"ok"');
        $this->assertSame(18, FakePlayerDriver::$volume);
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'activate'])->assertHasErrors(['needs an id']);
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'create', 'name' => 'Bad', 'steps' => [['operation' => 'nope']]])->assertHasErrors(['422']);
    }
}
