<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Scenes\SceneRunner;
use App\Domain\Scenes\SceneTriggers;
use App\Domain\Scenes\TriggerSubscriber;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\RunScene;
use App\Mcp\ApiBridge;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** Scene triggers by MQTT and by device events, with debounce and loop protection (workstream 8.1, docs/api/scenes.md). */
class SceneTriggersTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->inState(State::Standby)->create(['device_name' => 'TV']);
    }

    private function scene(array $triggers, array $attributes = []): Scene
    {
        return Scene::create(['name' => 'Movie', 'steps' => [['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 20]]], 'triggers' => $triggers] + $attributes);
    }

    private function started(): array
    {
        return Queue::pushed(RunScene::class)->map(fn ($job) => [$job->sceneId, $job->source])->all();
    }

    // ---- Topic filters ----

    #[DataProvider('filters')]
    public function test_topic_filters(string $filter, string $topic, bool $matches): void
    {
        $this->assertSame($matches, SceneTriggers::topicMatches($filter, $topic));
    }

    public static function filters(): array
    {
        return [
            'exact' => ['home/door', 'home/door', true],
            'other topic' => ['home/door', 'home/window', false],
            'plus' => ['home/+/open', 'home/door/open', true],
            'plus is one level' => ['home/+', 'home/door/open', false],
            'hash' => ['home/#', 'home/door/open', true],
            'hash matches the parent' => ['home/#', 'home', true],
            'hash alone' => ['#', 'any/thing', true],
            'longer topic' => ['home/door', 'home/door/open', false],
            'shorter topic' => ['home/door/open', 'home/door', false],
        ];
    }

    public function test_overlapping_filters(): void
    {
        $this->assertTrue(SceneTriggers::filtersOverlap('remoment/player/3/state', 'remoment/player/#'));
        $this->assertTrue(SceneTriggers::filtersOverlap('+/player/+', 'remoment/player/#'));
        $this->assertTrue(SceneTriggers::filtersOverlap('remoment/#', 'remoment/player/#'));
        $this->assertTrue(SceneTriggers::filtersOverlap('remoment/player', 'remoment/player/#'));
        $this->assertFalse(SceneTriggers::filtersOverlap('remoment/alerts/#', 'remoment/player/#'));
        $this->assertFalse(SceneTriggers::filtersOverlap('home/player/1', 'remoment/player/#'));
        $this->assertFalse(SceneTriggers::filtersOverlap('remoment', 'remoment/player/#'));
    }

    public function test_filter_syntax(): void
    {
        foreach (['a', 'a/b', 'a/+/b', 'a/#', '#', '+', 'a//b'] as $ok) {
            $this->assertTrue(SceneTriggers::validFilter($ok), $ok);
        }
        foreach (['', 'a/#/b', 'a#', 'a+/b', 'a/b+', '#/a'] as $bad) {
            $this->assertFalse(SceneTriggers::validFilter($bad), $bad);
        }
    }

    // ---- Saving ----

    public function test_triggers_are_validated_stored_and_shown(): void
    {
        $base = ['name' => 'Doorbell', 'steps' => [['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 5]]]];
        $good = [
            ['type' => 'mqtt', 'topic' => 'home/doorbell/#', 'payload' => 'ring', 'junk' => null],
            ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state', 'value' => 'playing'],
            ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'source', 'value' => 'HDMI 2'],
            ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'source'],
        ];

        // `junk` is an unknown field, so the whole list is refused
        $this->postJson('/api/scenes', $base + ['triggers' => $good])->assertStatus(422)->assertJsonValidationErrors('triggers.0.junk');
        unset($good[0]['junk']);

        $response = $this->postJson('/api/scenes', $base + ['triggers' => $good, 'trigger_debounce' => 30])->assertCreated();
        $response->assertJsonPath('data.trigger_debounce', 30)->assertJsonCount(4, 'data.triggers')
            ->assertJsonPath('data.triggers.0', ['type' => 'mqtt', 'topic' => 'home/doorbell/#', 'payload' => 'ring'])
            ->assertJsonPath('data.triggers.3', ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'source']);
        $this->getJson('/api/scenes')->assertJsonPath('data.0.triggers.1.value', 'playing');
    }

    public function test_bad_triggers_are_refused_with_the_field(): void
    {
        $base = ['name' => 'X', 'steps' => [['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 5]]]];
        $try = fn (array $trigger) => $this->postJson('/api/scenes', $base + ['triggers' => [$trigger]]);

        $try(['type' => 'tag'])->assertStatus(422)->assertJsonValidationErrors('triggers.0.type');
        $try(['type' => 'mqtt'])->assertJsonValidationErrors('triggers.0.topic');
        $try(['type' => 'mqtt', 'topic' => 'a/#/b'])->assertJsonValidationErrors('triggers.0.topic');
        // The server's own player topics would let a scene's steps start it again
        $try(['type' => 'mqtt', 'topic' => 'remoment/player/3/state'])->assertJsonValidationErrors('triggers.0.topic');
        $try(['type' => 'mqtt', 'topic' => 'remoment/#'])->assertJsonValidationErrors('triggers.0.topic');
        $try(['type' => 'mqtt', 'topic' => 'remoment/+/3/state'])->assertJsonValidationErrors('triggers.0.topic');
        $try(['type' => 'mqtt', 'topic' => '#'])->assertJsonValidationErrors('triggers.0.topic');
        $try(['type' => 'device', 'device_id' => 999, 'event' => 'state', 'value' => 'playing'])->assertJsonValidationErrors('triggers.0.device_id');
        $try(['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state', 'value' => 'sleeping'])->assertJsonValidationErrors('triggers.0.value');
        $try(['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state'])->assertJsonValidationErrors('triggers.0.value');
        $try(['type' => 'device', 'device_id' => $this->device->id, 'event' => 'volume'])->assertJsonValidationErrors('triggers.0.event');

        $this->postJson('/api/scenes', $base + ['triggers' => array_fill(0, 11, ['type' => 'mqtt', 'topic' => 'a'])])->assertJsonValidationErrors('triggers');
        $this->postJson('/api/scenes', $base + ['trigger_debounce' => 1])->assertStatus(422)->assertJsonValidationErrors('trigger_debounce');
        $this->postJson('/api/scenes', $base + ['trigger_debounce' => 99999])->assertStatus(422);
        $this->assertSame(0, Scene::count());
    }

    public function test_triggers_can_be_cleared(): void
    {
        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'a']]);

        $this->putJson("/api/scenes/{$scene->id}", ['triggers' => []])->assertOk()->assertJsonPath('data.triggers', []);

        $this->assertNull($scene->fresh()->triggers);
        $this->assertSame([], SceneTriggers::mqttFilters());
    }

    public function test_the_index_follows_saves_and_deletes(): void
    {
        $this->assertSame([], SceneTriggers::mqttFilters());

        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'home/a'], ['type' => 'mqtt', 'topic' => 'home/b']]);
        $this->assertEqualsCanonicalizing(['home/a', 'home/b'], SceneTriggers::mqttFilters());

        $scene->update(['triggers' => [['type' => 'mqtt', 'topic' => 'home/c']]]);
        $this->assertSame(['home/c'], SceneTriggers::mqttFilters());

        $scene->delete();
        $this->assertSame([], SceneTriggers::mqttFilters());
    }

    // ---- MQTT ----

    public function test_a_matching_message_queues_the_scene_as_a_trigger_run(): void
    {
        Queue::fake();
        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'home/doorbell/+', 'payload' => 'ring']]);

        $subscriber = app(TriggerSubscriber::class);
        $this->assertSame([], $subscriber->message('home/doorbell/front', 'nope'), 'the payload must equal');
        $this->assertSame([], $subscriber->message('home/window/front', 'ring'));
        $this->assertSame([$scene->id], $subscriber->message('home/doorbell/front', " ring\n"));

        $this->assertSame([[$scene->id, 'trigger']], $this->started());
        $this->assertSame('running', $scene->fresh()->last_run_status);
        $this->assertSame('trigger', $scene->fresh()->last_run_source);
    }

    public function test_a_retained_message_does_not_start_anything(): void
    {
        Queue::fake();
        $this->scene([['type' => 'mqtt', 'topic' => 'home/doorbell']]);

        $this->assertSame([], app(TriggerSubscriber::class)->message('home/doorbell', 'ring', retained: true));

        Queue::assertNothingPushed();
    }

    public function test_the_subscriber_follows_the_filters_of_the_scenes(): void
    {
        $client = new class
        {
            public array $log = [];

            public array $callbacks = [];

            public function subscribe(string $filter, callable $callback): void
            {
                $this->log[] = "+{$filter}";
                $this->callbacks[$filter] = $callback;
            }

            public function unsubscribe(string $filter): void
            {
                $this->log[] = "-{$filter}";
            }
        };
        $subscriber = app(TriggerSubscriber::class);

        $this->assertSame(['added' => [], 'removed' => []], $subscriber->sync($client));

        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'a/#'], ['type' => 'mqtt', 'topic' => 'a/#'], ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state', 'value' => 'playing']]);
        $subscriber->sync($client);
        $subscriber->sync($client); // nothing new
        $this->assertSame(['+a/#'], $client->log);

        $scene->update(['triggers' => [['type' => 'mqtt', 'topic' => 'b']]]);
        $subscriber->sync($client);
        $this->assertSame(['+a/#', '-a/#', '+b'], $client->log);

        // What the client calls back with reaches the scene
        Queue::fake();
        ($client->callbacks['b'])('b', 'x', false);
        $this->assertCount(1, $this->started());

        // A new connection has no subscriptions
        $subscriber->reset();
        $subscriber->sync($client);
        $this->assertSame('+b', end($client->log));
    }

    // ---- Device events ----

    public function test_a_state_change_starts_the_scene_for_that_state_of_that_device(): void
    {
        Queue::fake();
        $other = Device::factory()->create();
        $scene = $this->scene([['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state', 'value' => 'playing']]);

        DeviceStateChanged::dispatch($this->device->id, State::Paused, State::Standby);
        DeviceStateChanged::dispatch($other->id, State::Playing, State::Standby);
        $this->assertSame([], $this->started());

        DeviceStateChanged::dispatch($this->device->id, State::Playing, State::Standby);
        $this->assertSame([[$scene->id, 'trigger']], $this->started());
    }

    private function nowPlaying(?string $source, ?string $connector = null): NowPlayingUpdated
    {
        return new NowPlayingUpdated((string) $this->device->id, new NowPlaying(state: 'playing', source: new Source(name: $source, connector: $connector)), 'media');
    }

    public function test_a_source_change_starts_the_scene_but_the_first_report_and_repeats_do_not(): void
    {
        Queue::fake();
        $scene = $this->scene([['type' => 'device', 'device_id' => $this->device->id, 'event' => 'source']], ['trigger_debounce' => 5]);

        NowPlayingUpdated::dispatch(...array_values((array) $this->nowPlaying('Spotify')));
        $this->assertSame([], $this->started(), 'the first report is not a change');

        event($this->nowPlaying('Spotify'));
        $this->assertSame([], $this->started(), 'the same source again');

        event($this->nowPlaying('TV'));
        $this->assertSame([[$scene->id, 'trigger']], $this->started());
    }

    public function test_a_source_trigger_with_a_value_matches_the_name_case_insensitively(): void
    {
        Queue::fake();
        $this->scene([['type' => 'device', 'device_id' => $this->device->id, 'event' => 'source', 'value' => 'hdmi 2']]);

        event($this->nowPlaying('Spotify'));
        event($this->nowPlaying('HDMI 1'));
        $this->assertSame([], $this->started());

        event($this->nowPlaying('HDMI 2'));
        $this->assertCount(1, $this->started());
    }

    public function test_without_a_source_trigger_the_listener_keeps_no_state(): void
    {
        Queue::fake();

        event($this->nowPlaying('Spotify'));

        $this->assertNull(Cache::get("scene_trigger_source_{$this->device->id}"));
    }

    // ---- Debounce and loop protection ----

    public function test_a_second_trigger_within_the_debounce_is_ignored(): void
    {
        Queue::fake();
        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'a']], ['trigger_debounce' => 20]);
        $triggers = app(SceneTriggers::class);

        $this->assertSame([$scene->id], $triggers->mqtt('a', '1'));
        // the run ends (and its own guard with it): only the debounce is left
        Cache::forget("scene_guard_{$scene->id}");
        $this->assertSame([], $triggers->mqtt('a', '2'));

        Cache::forget("scene_trigger_debounce_{$scene->id}"); // the 20 s passed
        $this->assertSame([$scene->id], $triggers->mqtt('a', '3'));
        $this->assertCount(2, $this->started());
    }

    public function test_a_scene_does_not_retrigger_itself_while_it_runs_or_just_after(): void
    {
        Queue::fake();
        $scene = $this->scene([['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state', 'value' => 'playing']]);
        $seen = [];

        // A bridge whose step makes the device report playing, as a real play step would
        $bridge = new class($this->device->id, $seen) extends ApiBridge
        {
            public function __construct(private int $deviceId, public array &$seen)
            {
                parent::__construct(app('router'));
            }

            public function call(string $method, string $path, array $params = []): array
            {
                $this->seen[] = app(SceneTriggers::class)->deviceState($this->deviceId, State::Playing);

                return [200, ['status' => 'ok']];
            }
        };

        (new SceneRunner($bridge, app(\App\Domain\Scenes\SceneOperations::class)))->run($scene);
        $this->assertSame([[]], $seen, 'its own step did not start it');

        // A late report right after the run (within the guard) is still its own doing
        $this->assertSame([], app(SceneTriggers::class)->deviceState($this->device->id, State::Playing));
        Queue::assertNothingPushed();

        // Once the guard has passed, the trigger works again
        Cache::forget("scene_guard_{$scene->id}");
        $this->assertSame([$scene->id], app(SceneTriggers::class)->deviceState($this->device->id, State::Playing));
    }

    public function test_a_queued_trigger_run_is_guarded_until_it_ran(): void
    {
        Queue::fake();
        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'a']], ['trigger_debounce' => 5]);

        app(SceneTriggers::class)->mqtt('a', '');
        Cache::forget("scene_trigger_debounce_{$scene->id}");

        $this->assertTrue(SceneTriggers::guarded($scene->id));
        $this->assertSame([], app(SceneTriggers::class)->mqtt('a', ''), 'still queued or running');
    }

    public function test_a_scene_that_no_longer_exists_is_skipped(): void
    {
        Queue::fake();
        $scene = $this->scene([['type' => 'mqtt', 'topic' => 'a']]);
        // The index is stale for a moment (another process): the scene row is gone
        \DB::table('scenes')->where('id', $scene->id)->delete();

        $this->assertSame([], app(SceneTriggers::class)->mqtt('a', ''));
    }
}
