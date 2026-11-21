<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Scenes\DeviceTimers;
use App\Domain\Scenes\SceneOperations;
use App\Domain\Scenes\SceneRunner;
use App\Jobs\AdvanceDeviceTimer;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\DeviceTimer;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakePowerDriver;
use Tests\TestCase;

/** The sleep timer, the alarm's volume ramp and the timers under both (workstream 8.1, docs/api/scenes.md). */
class DeviceTimersTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        Carbon::setTestNow('2026-11-21 22:00:00');
        $this->device = Device::factory()->inState(State::Playing)->create(['device_name' => 'Bedroom']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<int> the volumes the fake device was set to, in order */
    private function volumes(): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter(FakePlayerDriver::$calls, fn ($c) => $c[0] === 'setVolume')));
    }

    private function calls(): array
    {
        return array_column(FakePlayerDriver::$calls, 0);
    }

    private function advanceTo(DeviceTimer $timer, string $time): void
    {
        app(DeviceTimers::class)->advance($timer->id, Carbon::parse($time));
    }

    // ---- Sleep timer: the API ----

    public function test_start_reports_the_timer_and_queues_the_first_event_for_the_start_of_the_fade(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 40;

        $response = $this->postJson("/api/devices/{$this->device->id}/sleep", ['minutes' => 30, 'fade_seconds' => 60])->assertCreated();

        $response->assertJsonPath('data.kind', 'sleep')->assertJsonPath('data.status', 'active')->assertJsonPath('data.action', 'stop')
            ->assertJsonPath('data.fade_seconds', 60)->assertJsonPath('data.original_volume', 40)->assertJsonPath('data.remaining_seconds', 1800);

        // The first event is the first fade step, 60 s before the end: 29 minutes from now
        Queue::assertPushed(AdvanceDeviceTimer::class, fn (AdvanceDeviceTimer $job) => $job->delay->getTimestamp() === now()->addSeconds(1740)->getTimestamp());
        $this->getJson("/api/devices/{$this->device->id}/sleep")->assertJsonPath('data.id', $response->json('data.id'));
    }

    public function test_validation_and_capabilities(): void
    {
        Queue::fake();
        $url = "/api/devices/{$this->device->id}/sleep";

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('minutes');
        $this->postJson($url, ['minutes' => 0])->assertStatus(422);
        $this->postJson($url, ['minutes' => 481])->assertStatus(422);
        $this->postJson($url, ['minutes' => 5, 'action' => 'explode'])->assertStatus(422);
        $this->postJson($url, ['minutes' => 1, 'fade_seconds' => 120])->assertStatus(422)->assertJsonValidationErrors('fade_seconds');
        // The fake driver has no power contract: standby is refused, stop is fine
        $this->postJson($url, ['minutes' => 5, 'action' => 'standby'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson($url, ['minutes' => 5, 'fade_seconds' => 0])->assertCreated();
        $this->postJson('/api/devices/999/sleep', ['minutes' => 5])->assertNotFound();
    }

    public function test_get_is_null_without_a_timer_and_delete_is_not_an_error(): void
    {
        $this->getJson("/api/devices/{$this->device->id}/sleep")->assertOk()->assertJsonPath('data', null);
        $this->deleteJson("/api/devices/{$this->device->id}/sleep")->assertOk()->assertJsonPath('cancelled', false);
    }

    public function test_a_new_timer_replaces_the_running_one(): void
    {
        Queue::fake();

        $first = $this->postJson("/api/devices/{$this->device->id}/sleep", ['minutes' => 30])->json('data.id');
        $second = $this->postJson("/api/devices/{$this->device->id}/sleep", ['minutes' => 10])->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame('cancelled', DeviceTimer::find($first)->status);
        $this->assertSame(1, DeviceTimer::where('status', 'active')->count());
    }

    // ---- Sleep timer: the fade, the stop and the restore ----

    public function test_it_fades_stops_and_puts_the_volume_back(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 40;
        $timer = app(DeviceTimers::class)->startSleep($this->device, 10, 30, 'stop'); // ends 22:10:00, fades from 22:09:30 in 3 steps of 10 s

        $this->advanceTo($timer, '2026-11-21 22:05:00');
        $this->assertSame([], $this->volumes(), 'nothing is due before the fade');

        $this->advanceTo($timer, '2026-11-21 22:09:30');
        $this->assertSame([27], $this->volumes());
        $this->advanceTo($timer, '2026-11-21 22:09:40');
        $this->advanceTo($timer, '2026-11-21 22:09:50');
        $this->assertSame([27, 13, 0], $this->volumes());
        $this->assertSame('active', $timer->fresh()->status);

        $this->advanceTo($timer, '2026-11-21 22:10:00');

        $this->assertSame('done', $timer->fresh()->status);
        $this->assertNotNull($timer->fresh()->finished_at);
        $this->assertContains('stop', $this->calls());
        $this->assertSame(40, FakePlayerDriver::$volume, 'the volume is back');
        // stop comes before the restore, so the restored volume is never heard
        $this->assertLessThan(array_search('setVolume', array_reverse($this->calls(), true)), array_search('stop', $this->calls()));
    }

    public function test_a_late_job_applies_only_the_newest_due_volume_and_still_finishes(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 30;
        $timer = app(DeviceTimers::class)->startSleep($this->device, 10, 30);

        $this->advanceTo($timer, '2026-11-21 22:30:00'); // the worker was away: everything is due

        $this->assertSame([0, 30], $this->volumes(), 'to the lowest due volume, then back');
        $this->assertSame('done', $timer->fresh()->status);
    }

    public function test_without_a_fade_it_only_stops(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 30;
        $timer = app(DeviceTimers::class)->startSleep($this->device, 5, 0);

        $this->advanceTo($timer, '2026-11-21 22:05:00');

        $this->assertSame([], $this->volumes());
        $this->assertContains('stop', $this->calls());
        $this->assertSame('done', $timer->fresh()->status);
    }

    public function test_standby_at_the_end_when_asked(): void
    {
        Queue::fake();
        $power = Device::factory()->driver(FakePowerDriver::class)->inState(State::Playing)->create();
        $timer = app(DeviceTimers::class)->startSleep($power, 5, 0, 'standby');

        FakePowerDriver::reset();
        $this->advanceTo($timer, '2026-11-21 22:05:00');

        $this->assertSame('done', $timer->fresh()->status);
        $this->assertContains('standby', array_column($timer->fresh()->state['log'], 'step'));
    }

    public function test_cancel_after_the_fade_began_restores_the_volume(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 40;
        $timer = app(DeviceTimers::class)->startSleep($this->device, 10, 30);
        $this->advanceTo($timer, '2026-11-21 22:09:40');
        $this->assertNotSame(40, FakePlayerDriver::$volume);

        $this->deleteJson("/api/devices/{$this->device->id}/sleep")->assertJsonPath('cancelled', true);

        $this->assertSame(40, FakePlayerDriver::$volume);
        $this->assertSame('cancelled', $timer->fresh()->status);
        // A queued job that fires afterwards does nothing
        FakePlayerDriver::$calls = [];
        $this->advanceTo($timer, '2026-11-21 22:10:00');
        $this->assertSame([], FakePlayerDriver::$calls);
    }

    public function test_cancel_before_the_fade_leaves_the_volume_alone(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 40;
        $timer = app(DeviceTimers::class)->startSleep($this->device, 10, 30);
        FakePlayerDriver::$calls = [];

        app(DeviceTimers::class)->cancel($timer);

        $this->assertSame([], $this->volumes());
    }

    public function test_a_job_for_a_removed_timer_or_device_is_harmless(): void
    {
        (new AdvanceDeviceTimer(12345))->handle(app(DeviceTimers::class));

        Queue::fake();
        $timer = app(DeviceTimers::class)->startSleep($this->device, 5, 0);
        $this->device->delete();

        $this->assertNull(DeviceTimer::find($timer->id), 'deleting the device ends its timers');
        (new AdvanceDeviceTimer($timer->id))->handle(app(DeviceTimers::class));
    }

    // ---- The alarm ramp ----

    public function test_a_ramp_sets_the_first_level_now_and_rises_to_the_target(): void
    {
        Queue::fake();
        $timer = app(DeviceTimers::class)->startRamp($this->device, 10, 5, 45);

        $this->assertSame([5], $this->volumes());
        Queue::assertPushed(AdvanceDeviceTimer::class);

        $this->advanceTo($timer, '2026-11-21 22:05:00');
        $this->assertSame(25, FakePlayerDriver::$volume);
        $this->advanceTo($timer, '2026-11-21 22:10:00');
        $this->assertSame(45, FakePlayerDriver::$volume);
        $this->assertSame('done', $timer->fresh()->status);
    }

    public function test_the_ramp_ends_when_someone_takes_over_the_volume(): void
    {
        Queue::fake();
        $timer = app(DeviceTimers::class)->startRamp($this->device, 10, 5, 45);
        $this->advanceTo($timer, '2026-11-21 22:01:00');
        $set = FakePlayerDriver::$volume;

        FakePlayerDriver::$volume = 80; // someone turned the knob
        $this->advanceTo($timer, '2026-11-21 22:02:00');

        $this->assertSame('taken_over', $timer->fresh()->status);
        $this->assertSame(80, FakePlayerDriver::$volume, 'the ramp leaves their volume alone');
        $this->assertNotSame($set, 80);
    }

    public function test_the_ramp_ends_when_the_device_goes_to_standby(): void
    {
        Queue::fake();
        $timer = app(DeviceTimers::class)->startRamp($this->device, 10, 5, 45);
        DeviceCache::updateState($this->device->id, State::Standby);
        FakePlayerDriver::$calls = [];

        $this->advanceTo($timer, '2026-11-21 22:01:00');

        $this->assertSame('stopped', $timer->fresh()->status);
        $this->assertSame([], $this->volumes());
    }

    // ---- A scene with an alarm ----

    private function alarmScene(array $attributes = []): Scene
    {
        return Scene::create(['name' => 'Wake up', 'steps' => [['operation' => 'transport', 'device_id' => $this->device->id, 'params' => ['action' => 'play']]],
            'alarm' => ['device_id' => $this->device->id, 'minutes' => 20, 'start_volume' => 8, 'target_volume' => 40]] + $attributes);
    }

    public function test_running_an_alarm_scene_sets_the_start_volume_runs_the_steps_and_starts_the_ramp(): void
    {
        Queue::fake();
        $scene = $this->alarmScene();

        $outcome = app(SceneRunner::class)->run($scene, 'schedule');

        $this->assertSame('ok', $outcome['status']);
        $last = end($outcome['results']);
        $this->assertSame('alarm', $last['type']);
        $this->assertTrue($last['ok']);
        $this->assertSame('ramp', DeviceTimer::first()->kind);
        $this->assertSame($scene->id, DeviceTimer::first()->scene_id);
        // start volume before the play step, the ramp's first level after it
        $this->assertSame(['setVolume', 'play', 'setVolume'], $this->calls());
        $this->assertSame([8, 8], $this->volumes());
    }

    public function test_the_alarm_does_not_start_when_the_steps_failed(): void
    {
        Queue::fake();
        $scene = $this->alarmScene(['steps' => [['operation' => 'transport', 'device_id' => $this->device->id, 'params' => ['action' => 'play']]]]);
        FakePlayerDriver::$throw = new \RuntimeException('device down');

        $outcome = app(SceneRunner::class)->run($scene);

        $this->assertSame('failed', $outcome['status']);
        $this->assertSame(0, DeviceTimer::count());
    }

    public function test_the_alarm_is_validated_and_shown_by_the_api(): void
    {
        $base = ['name' => 'Wake', 'steps' => [['operation' => 'transport', 'device_id' => $this->device->id, 'params' => ['action' => 'play']]]];
        $alarm = ['device_id' => $this->device->id, 'minutes' => 15, 'start_volume' => 5, 'target_volume' => 35];

        $id = $this->postJson('/api/scenes', $base + ['alarm' => $alarm])->assertCreated()->assertJsonPath('data.alarm.minutes', 15)->json('data.id');
        $this->getJson('/api/scenes')->assertJsonPath('data.0.alarm.target_volume', 35);

        $this->postJson('/api/scenes', $base + ['alarm' => ['target_volume' => 4] + $alarm])->assertStatus(422)->assertJsonValidationErrors('alarm.target_volume');
        $this->postJson('/api/scenes', $base + ['alarm' => ['minutes' => 61] + $alarm])->assertStatus(422)->assertJsonValidationErrors('alarm.minutes');
        $this->postJson('/api/scenes', $base + ['alarm' => ['device_id' => 999] + $alarm])->assertStatus(422)->assertJsonValidationErrors('alarm.device_id');
        $this->postJson('/api/scenes', $base + ['alarm' => ['volume' => 3] + $alarm])->assertStatus(422)->assertJsonValidationErrors('alarm.volume');

        $this->putJson("/api/scenes/{$id}", ['alarm' => null])->assertOk()->assertJsonPath('data.alarm', null);
    }

    // ---- Operations and MCP ----

    public function test_the_sleep_timer_can_be_a_scene_step(): void
    {
        Queue::fake();
        $operations = app(SceneOperations::class)->all();
        $this->assertArrayHasKey('startSleep', $operations);
        $this->assertArrayHasKey('cancelSleep', $operations);
        $this->assertArrayNotHasKey('getSleep', $operations);

        $scene = Scene::create(['name' => 'Bedtime', 'steps' => [['operation' => 'startSleep', 'device_id' => $this->device->id, 'params' => ['minutes' => 20]]]]);
        $this->assertSame('ok', app(SceneRunner::class)->run($scene)['status']);
        $this->assertSame(1, DeviceTimer::where('kind', 'sleep')->count());
    }

    public function test_mcp_sleep_actions(): void
    {
        Queue::fake();

        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'sleep', 'device_id' => $this->device->id, 'minutes' => 25])->assertOk()->assertSee('"kind":"sleep"');
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'sleep_status', 'device_id' => $this->device->id])->assertOk()->assertSee('"status":"active"');
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'sleep_cancel', 'device_id' => $this->device->id])->assertOk()->assertSee('"cancelled":true');
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'sleep', 'device_id' => $this->device->id])->assertHasErrors(['needs minutes']);
        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'sleep_status'])->assertHasErrors(['needs a device_id']);
    }

    public function test_mcp_can_set_and_clear_triggers_and_an_alarm(): void
    {
        $steps = [['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 10]]];
        $alarm = ['device_id' => $this->device->id, 'minutes' => 5, 'start_volume' => 1, 'target_volume' => 20];
        $triggers = [['type' => 'mqtt', 'topic' => 'home/door']];

        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'create', 'name' => 'Door', 'steps' => $steps, 'triggers' => $triggers, 'alarm' => $alarm, 'trigger_debounce' => 30])
            ->assertOk()->assertSee('home/door')->assertSee('"trigger_debounce":30');
        $id = Scene::first()->id;

        ReMomentServer::tool(Tools\Scenes::class, ['action' => 'update', 'id' => $id, 'triggers' => []])->assertOk();
        $this->assertSame([], \App\Domain\Scenes\Scenes::item(Scene::first())['triggers']);
        $this->assertNotNull(Scene::first()->alarm);
    }
}
