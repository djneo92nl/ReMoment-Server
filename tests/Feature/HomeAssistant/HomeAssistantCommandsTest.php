<?php

namespace Tests\Feature\HomeAssistant;

use App\Console\Commands\HaListenCommand;
use App\Domain\Device\State;
use App\Domain\HomeAssistant\CommandHandler;
use App\Domain\HomeAssistant\CommandSubscriber;
use App\Domain\HomeAssistant\DiscoveryPublisher;
use App\Domain\HomeAssistant\HomeAssistant;
use App\Domain\Scenes\SceneTriggers;
use App\Jobs\RunScene;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** The opt-in Home Assistant command path: a recorder stands in for the broker (docs/architecture/home-assistant.md). */
class HomeAssistantCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->inState(State::Playing)->create(['device_name' => 'Kitchen']);
        $this->mqtt->published = [];
        RateLimiter::clear('ha-command');
        RateLimiter::clear('ha-command-errors');
        Queue::fake();
        config(['mqtt.ha_command_debounce' => 2]);
    }

    private function on(): void
    {
        HomeAssistant::setEnabled(true);
        HomeAssistant::setCommandsEnabled(true);
    }

    private function scene(string $name = 'Movie'): Scene
    {
        return Scene::create(['name' => $name, 'steps' => [['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 20]]]]);
    }

    /** @return list<array<string, mixed>> */
    private function results(): array
    {
        return collect($this->mqtt->published)->where('topic', 'remoment/ha/events/command_result')->map(fn ($p) => json_decode($p['message'], true))->values()->all();
    }

    private function command(string $topic, string|array $payload = '', bool $retained = false): ?array
    {
        return app(CommandHandler::class)->handle($topic, is_array($payload) ? json_encode($payload) : $payload, $retained);
    }

    public function test_it_does_nothing_until_both_switches_are_on(): void
    {
        $scene = $this->scene();
        HomeAssistant::setEnabled(true);
        $this->assertNull($this->command("remoment/ha/command/scene/{$scene->id}", 'PRESS'));   // commands off
        HomeAssistant::setEnabled(false);
        HomeAssistant::setCommandsEnabled(true);
        $this->assertNull($this->command("remoment/ha/command/scene/{$scene->id}", 'PRESS'));   // discovery off

        Queue::assertNothingPushed();
        $this->assertSame([], $this->mqtt->published);
    }

    public function test_the_subscriber_follows_the_switches_and_ignores_retained_messages(): void
    {
        $client = new class
        {
            public array $subscribed = [];

            public array $unsubscribed = [];

            public $callback;

            public function subscribe(string $filter, callable $cb): void
            {
                $this->subscribed[] = $filter;
                $this->callback = $cb;
            }

            public function unsubscribe(string $filter): void
            {
                $this->unsubscribed[] = $filter;
            }
        };
        $sub = app(CommandSubscriber::class);

        $this->assertSame('unchanged', $sub->sync($client));
        $this->assertSame([], $client->subscribed);
        $this->on();
        $this->assertSame('subscribed', $sub->sync($client));
        $this->assertSame('unchanged', $sub->sync($client));
        $this->assertSame(['remoment/ha/command/#'], $client->subscribed);

        $scene = $this->scene();
        ($client->callback)("remoment/ha/command/scene/{$scene->id}", 'PRESS', true);   // the broker's replay
        Queue::assertNothingPushed();
        ($client->callback)("remoment/ha/command/scene/{$scene->id}", 'PRESS', false);
        Queue::assertPushed(RunScene::class, fn ($job) => $job->sceneId === $scene->id && $job->source === 'home_assistant');

        HomeAssistant::setCommandsEnabled(false);
        $this->assertSame('unsubscribed', $sub->sync($client));
        $this->assertSame(['remoment/ha/command/#'], $client->unsubscribed);
    }

    public function test_a_scene_button_starts_the_scene_and_answers(): void
    {
        $this->on();
        $scene = $this->scene();

        $r = $this->command("remoment/ha/command/scene/{$scene->id}", 'PRESS');

        $this->assertTrue($r['ok']);
        Queue::assertPushed(RunScene::class, 1);
        $this->assertSame([['event_type' => 'ok', 'command' => "scene/{$scene->id}", 'ok' => true, 'at' => $r['at'], 'scene_id' => $scene->id, 'scene' => 'Movie']], $this->results());
        $this->assertFalse(collect($this->mqtt->published)->firstWhere('topic', 'remoment/ha/events/command_result')['retain']);
    }

    public function test_scene_errors_unknown_busy_and_doubled(): void
    {
        $this->on();
        $scene = $this->scene();

        $this->assertSame('not_found', $this->command('remoment/ha/command/scene/9999', 'PRESS')['error']);
        $this->assertSame('unknown_command', $this->command('remoment/ha/command/scenes/1', 'PRESS')['error']);
        $this->assertSame('unknown_command', $this->command('remoment/ha/command/scene/1/../2', 'PRESS')['error']);

        $this->command("remoment/ha/command/scene/{$scene->id}", 'PRESS');
        $this->assertSame('busy', $this->command("remoment/ha/command/scene/{$scene->id}", 'PRESS')['error'], 'a running scene is guarded');

        Cache::forget("scene_guard_{$scene->id}");
        $this->assertSame('debounced', $this->command("remoment/ha/command/scene/{$scene->id}", 'PRESS')['error']);
        Queue::assertPushed(RunScene::class, 1);
        $this->assertFalse(SceneTriggers::guarded(99999));
    }

    public function test_an_allow_listed_operation_runs_through_the_api_and_echoes_the_request_id(): void
    {
        $this->on();

        $r = $this->command('remoment/ha/command/operation', ['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 33], 'request_id' => 'r-1']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(33, FakePlayerDriver::$volume);
        $this->assertSame('r-1', $this->results()[0]['request_id']);
        $this->assertSame('ok', $this->results()[0]['event_type']);
    }

    public function test_operations_outside_the_allow_list_are_refused(): void
    {
        $this->on();
        $refuse = function (array $payload, string $code) {
            $r = $this->command('remoment/ha/command/operation', $payload);
            $this->assertFalse($r['ok']);
            $this->assertSame($code, $r['error'], json_encode($payload));
        };

        $refuse(['operation' => 'activateScene', 'params' => ['scene' => 1]], 'not_allowed');       // scenes do not run scenes
        $refuse(['operation' => 'createScene'], 'not_allowed');
        $refuse(['operation' => 'adminLogin'], 'not_allowed');
        $refuse(['operation' => 'deleteSyncTarget'], 'not_allowed');                                // admin-token operation
        $refuse(['operation' => 'nope'], 'not_allowed');
        $refuse(['type' => 'delay', 'seconds' => 5], 'invalid');
        $refuse(['operation' => 'setVolume'], 'invalid');                                           // no device
        $refuse(['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 'loud']], 'invalid');
        $this->assertSame('invalid', $this->command('remoment/ha/command/operation', '[1,2]')['error']);
        $this->assertSame('invalid', $this->command('remoment/ha/command/operation', 'not json')['error']);
        $this->assertSame(30, FakePlayerDriver::$volume, 'nothing reached the device');
    }

    public function test_a_failing_operation_reports_the_error(): void
    {
        $this->on();

        $r = $this->command('remoment/ha/command/operation', ['operation' => 'setVolume', 'device_id' => 999999, 'params' => ['volume' => 10]]);

        $this->assertFalse($r['ok']);
        $this->assertSame('failed', $r['error']);
        $this->assertSame(404, $r['status']);
        $this->assertSame('error', $this->results()[0]['event_type']);
    }

    public function test_rate_limit_debounce_and_payload_cap_protect_the_server(): void
    {
        $this->on();
        config(['mqtt.ha_command_rate' => 3]);
        $scene = $this->scene();
        $payload = fn (int $v) => ['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => $v]];

        $this->assertTrue($this->command('remoment/ha/command/operation', $payload(10))['ok']);
        $this->assertSame('debounced', $this->command('remoment/ha/command/operation', $payload(10))['error'], 'the same command twice');
        $this->assertTrue($this->command('remoment/ha/command/operation', $payload(11))['ok']);
        $limited = $this->command('remoment/ha/command/operation', $payload(12));
        $this->assertSame('rate_limited', $limited['error']);
        $this->assertSame(11, FakePlayerDriver::$volume, 'the limited one did not run');
        Queue::assertNothingPushed();
        $this->assertNull($scene->fresh()->last_run_at);

        RateLimiter::clear('ha-command');
        $this->assertSame('too_large', $this->command('remoment/ha/command/operation', str_repeat('x', CommandHandler::MAX_PAYLOAD + 1))['error']);
    }

    public function test_error_answers_are_rate_limited_so_a_flood_is_not_echoed(): void
    {
        $this->on();
        config(['mqtt.ha_command_rate' => 1000]);

        for ($i = 0; $i < 30; $i++) {
            $this->command('remoment/ha/command/bogus'.$i, '');
        }

        $this->assertCount(10, $this->results());
    }

    public function test_messages_outside_the_command_prefix_are_ignored_and_results_never_loop_back(): void
    {
        $this->on();

        $this->assertNull($this->command('remoment/ha/events/command_result', '{"operation":"setVolume"}'));
        $this->assertNull($this->command('remoment/player/1/state', ''));
        $this->assertNull($this->command('remoment/ha/commandx/scene/1', ''));
        $this->assertSame([], $this->mqtt->published);
        // the results topic is outside the one filter the subscriber uses
        $this->assertFalse(str_starts_with('remoment/ha/events/command_result', CommandHandler::PREFIX));
    }

    public function test_discovery_lists_a_button_per_scene_only_while_commands_are_on(): void
    {
        $scene = $this->scene('Movie night');
        $publisher = app(DiscoveryPublisher::class);
        HomeAssistant::setEnabled(true);
        $publisher->publishConfigs(true);
        $this->assertNull(collect($this->mqtt->published)->firstWhere('topic', "homeassistant/button/remoment_scene_{$scene->id}/config"));
        $this->assertNotNull(collect($this->mqtt->published)->firstWhere('topic', 'homeassistant/event/remoment_event_command_result/config'));

        HomeAssistant::setCommandsEnabled(true);
        $this->mqtt->published = [];
        $publisher->publishConfigs(true);
        $button = collect($this->mqtt->published)->last(fn ($p) => $p['topic'] === "homeassistant/button/remoment_scene_{$scene->id}/config");
        $config = json_decode($button['message'], true);
        $this->assertTrue($button['retain']);
        $this->assertSame("remoment/ha/command/scene/{$scene->id}", $config['command_topic']);
        $this->assertSame('PRESS', $config['payload_press']);
        $this->assertSame('Movie night', $config['name']);
        $this->assertSame(['remoment_server'], $config['device']['identifiers']);

        // a new scene gets its button, a deleted one loses it, with no manual republish
        $this->mqtt->published = [];
        $second = $this->scene('Alarm');
        $this->assertNotNull(collect($this->mqtt->published)->firstWhere('topic', "homeassistant/button/remoment_scene_{$second->id}/config"));
        $second->delete();
        $gone = collect($this->mqtt->published)->last(fn ($p) => $p['topic'] === "homeassistant/button/remoment_scene_{$second->id}/config");
        $this->assertSame('', $gone['message']);

        // switching the commands off removes the buttons
        $this->mqtt->published = [];
        $this->artisan('ha:commands', ['state' => 'off'])->assertSuccessful();
        $this->assertSame('', collect($this->mqtt->published)->last(fn ($p) => $p['topic'] === "homeassistant/button/remoment_scene_{$scene->id}/config")['message']);
    }

    public function test_ha_commands_command_shows_and_sets_the_state(): void
    {
        $this->artisan('ha:commands')->expectsOutputToContain('Commands: off')->assertSuccessful();
        $this->artisan('ha:commands', ['state' => 'on'])->expectsOutputToContain('Commands: on')->assertSuccessful();
        $this->assertTrue(HomeAssistant::commandsEnabled());
        $this->artisan('ha:commands', ['state' => 'maybe'])->assertFailed();
    }

    public function test_a_running_scene_run_by_a_button_does_not_trigger_a_scene_on_the_command_topic(): void
    {
        // a scene may not use the command topics as an MQTT trigger (it would run twice per press)
        $errors = SceneTriggers::validate([['type' => 'mqtt', 'topic' => 'remoment/ha/command/scene/+']]);
        $this->assertNotSame([], $errors);
        $this->assertTrue(class_exists(HaListenCommand::class));
    }
}
