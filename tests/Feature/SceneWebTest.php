<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Jobs\RunScene;
use App\Livewire\SceneEditor;
use App\Livewire\SceneList;
use App\Mcp\ApiBridge;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** The scenes pages (workstream 8.1b): list with Run, and the editor built from the operations' schemas. */
class SceneWebTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->inState(State::Playing)->create(['device_name' => 'Kitchen']);
    }

    private function scene(array $steps, array $attributes = []): Scene
    {
        return Scene::create(['name' => 'Evening', 'icon' => 'moon', 'color' => '#336699', 'steps' => $steps] + $attributes);
    }

    private function volume(int $level): array
    {
        return ['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => $level]];
    }

    /**
     * Livewire's test harness switches all middleware off, including the route-model binding the API needs, so
     * the component tests answer the steps from a fake bridge; SceneTest runs scenes through the real API.
     */
    private function fakeBridge(array $statuses = []): void
    {
        $this->app->instance(ApiBridge::class, new class($statuses) extends ApiBridge
        {
            public function __construct(private array $statuses)
            {
                parent::__construct(app('router'));
            }

            public function call(string $method, string $path, array $params = []): array
            {
                $status = array_shift($this->statuses) ?? 200;

                return [$status, $status < 300 ? ['status' => 'ok'] : ['error' => 'unreachable', 'message' => 'Device is not reachable.']];
            }
        });
    }

    public function test_pages_render_for_guests_and_the_nav_links_them(): void
    {
        $scene = $this->scene([$this->volume(20)]);

        $this->get('/scenes')->assertOk()->assertSee('Evening')->assertSee(route('scenes.create'), false);
        $this->get('/scenes/create')->assertOk()->assertSee('Add action');
        $this->get("/scenes/{$scene->id}/edit")->assertOk()->assertSee('Evening');
        $this->get('/devices')->assertSee(route('scenes.index'), false);
    }

    public function test_run_executes_a_scene_and_reports_it(): void
    {
        $scene = $this->scene([$this->volume(41)]);

        $this->fakeBridge();

        Livewire::test(SceneList::class)->call('run', $scene->id)->assertSee('ran.');

        $this->assertSame('ok', $scene->fresh()->last_run_status);
    }

    public function test_run_reports_failed_steps(): void
    {
        $scene = $this->scene([$this->volume(41), $this->volume(5)]);
        $this->fakeBridge([200, 503]);

        Livewire::test(SceneList::class)->call('run', $scene->id)->assertSet('feedbackIsError', true)->assertSee('1 of 2 steps failed');
    }

    public function test_run_queues_a_scene_with_delays(): void
    {
        Queue::fake();
        $scene = $this->scene([$this->volume(41), ['type' => 'delay', 'seconds' => 3]]);

        Livewire::test(SceneList::class)->call('run', $scene->id)->assertSee('background');

        Queue::assertPushed(RunScene::class);
    }

    public function test_delete(): void
    {
        $scene = $this->scene([$this->volume(1)]);

        Livewire::test(SceneList::class)->call('delete', $scene->id);

        $this->assertSame(0, Scene::count());
    }

    public function test_the_editor_creates_a_scene_from_the_form(): void
    {
        Livewire::test(SceneEditor::class)
            ->set('name', 'Night')
            ->set('icon', 'moon')
            ->set('color', SceneEditor::COLORS['indigo'])
            ->set('stopOnError', true)
            ->call('addOperation')
            ->set('steps.0.operation', 'setVolume')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->set('steps.0.params.volume', '15')
            ->call('addDelay')
            ->set('steps.1.seconds', '4')
            ->set('scheduleEnabled', true)
            ->set('scheduleTime', '22:30')
            ->set('scheduleDays', ['1', '5'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('scenes.index'));

        $scene = Scene::firstOrFail();
        $this->assertSame('Night', $scene->name);
        $this->assertTrue($scene->stop_on_error);
        $this->assertSame([1, 5], $scene->schedule_days);
        $this->assertSame('22:30', $scene->schedule_time);
        $this->assertSame([
            ['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 15]],
            ['type' => 'delay', 'seconds' => 4],
        ], $scene->steps);
    }

    public function test_the_editor_shows_step_errors_and_saves_nothing(): void
    {
        Livewire::test(SceneEditor::class)
            ->set('name', 'Bad')
            ->call('addOperation')
            ->set('steps.0.operation', 'setVolume')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->set('steps.0.params.volume', '500')
            ->call('save')
            ->assertNoRedirect()
            ->assertSee('volume');

        $this->assertSame(0, Scene::count());
    }

    public function test_the_editor_needs_a_name_and_a_time_for_a_schedule(): void
    {
        Livewire::test(SceneEditor::class)->call('save')->assertHasErrors(['name']);
        Livewire::test(SceneEditor::class)->set('name', 'X')->set('scheduleEnabled', true)->call('save')->assertHasErrors(['scheduleTime']);
    }

    public function test_the_editor_loads_and_updates_a_scene_and_changing_the_action_clears_params(): void
    {
        $scene = $this->scene([$this->volume(20)], ['schedule_enabled' => true, 'schedule_time' => '07:00', 'schedule_days' => [2]]);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])
            ->assertSet('name', 'Evening')
            ->assertSet('steps.0.params.volume', '20')
            ->assertSet('scheduleDays', [2])
            ->set('steps.0.operation', 'setMute')
            ->assertSet('steps.0.params', [])
            ->set('steps.0.params.muted', 'true')
            ->set('name', 'Morning')
            ->call('save')
            ->assertHasNoErrors();

        $scene->refresh();
        $this->assertSame('Morning', $scene->name);
        $this->assertSame(['operation' => 'setMute', 'device_id' => $this->device->id, 'params' => ['muted' => true]], $scene->steps[0]);
    }

    public function test_steps_can_be_moved_and_removed(): void
    {
        $c = Livewire::test(SceneEditor::class)->call('addDelay')->call('addOperation')->call('moveStep', 1, -1);
        $this->assertSame('operation', $c->get('steps.0.kind'));

        $c->call('removeStep', 0)->assertCount('steps', 1);
    }

    public function test_playlist_and_station_pickers_list_names_and_save_the_id(): void
    {
        $playlist = \App\Models\Media\Playlist::create(['name' => 'Sunday jazz']);
        $station = \App\Models\RadioStation::create(['name' => 'Radio Test']);

        $c = Livewire::test(SceneEditor::class)
            ->set('name', 'Radio')
            ->call('addOperation')
            ->set('steps.0.operation', 'playPlaylist')
            ->assertSee('Sunday jazz')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->set('steps.0.params.playlist_id', (string) $playlist->id)
            ->call('addOperation')
            ->set('steps.1.operation', 'playRadio')
            ->assertSee('Radio Test')
            ->set('steps.1.device_id', (string) $this->device->id)
            ->set('steps.1.params.station', (string) $station->id)
            ->call('save')
            ->assertHasNoErrors();

        $steps = Scene::firstOrFail()->steps;
        $this->assertSame($playlist->id, $steps[0]['params']['playlist_id']);
        $this->assertSame($station->id, $steps[1]['params']['station']);
    }

    public function test_a_picker_keeps_a_deleted_record_visible(): void
    {
        $scene = $this->scene([['operation' => 'playRadio', 'device_id' => $this->device->id, 'params' => ['station' => 4242]]]);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])->assertSee('Missing (#4242)');
    }

    public function test_control_has_no_playlist_picker(): void
    {
        \App\Models\Media\Playlist::create(['name' => 'Sunday jazz']);
        config(['profile.current' => 'control']);

        Livewire::test(SceneEditor::class)->call('addOperation')->set('steps.0.operation', 'playPlaylist')->assertDontSee('Sunday jazz');
    }

    public function test_album_artist_and_track_pickers_search_and_save_the_id(): void
    {
        $artist = \App\Models\Media\Artist::create(['name' => 'Portishead']);
        $album = \App\Models\Media\Album::create(['name' => 'Dummy', 'artist_id' => $artist->id]);
        $track = \App\Models\Media\Track::create(['name' => 'Sour Times', 'artist_id' => $artist->id, 'album_id' => $album->id]);
        \App\Models\Media\Artist::create(['name' => 'Massive Attack']);

        $c = Livewire::test(SceneEditor::class)
            ->set('name', 'Trip hop')
            ->call('addOperation')
            ->set('steps.0.operation', 'playAlbum')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->assertDontSee('Dummy')
            ->set('pickerSearch.0.album_id', 'dum')
            ->assertSee('Dummy — Portishead')
            ->set('steps.0.params.album_id', (string) $album->id)
            ->call('addOperation')
            ->set('steps.1.operation', 'playArtist')
            ->set('steps.1.device_id', (string) $this->device->id)
            ->set('pickerSearch.1.artist_id', 'portis')
            ->assertDontSee('Massive Attack')
            ->set('steps.1.params.artist_id', (string) $artist->id)
            ->call('addOperation')
            ->set('steps.2.operation', 'playTrack')
            ->set('steps.2.device_id', (string) $this->device->id)
            ->set('pickerSearch.2.track_id', 'sour')
            ->assertSee('Sour Times — Portishead')
            ->set('steps.2.params.track_id', (string) $track->id)
            ->call('save')
            ->assertHasNoErrors();

        $steps = Scene::firstOrFail()->steps;
        $this->assertSame([$album->id, $artist->id, $track->id], [$steps[0]['params']['album_id'], $steps[1]['params']['artist_id'], $steps[2]['params']['track_id']]);

        // Reopened: the chosen record is shown by name without searching again.
        Livewire::test(SceneEditor::class, ['sceneId' => Scene::first()->id])->assertSee('Dummy — Portishead')->assertSee('Portishead');
    }

    public function test_a_search_picker_keeps_a_deleted_record_visible_and_needs_the_library(): void
    {
        $scene = $this->scene([['operation' => 'playArtist', 'device_id' => $this->device->id, 'params' => ['artist_id' => 777]]]);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])->assertSee('Missing (#777)')->assertSee('Search artists');

        config(['profile.current' => 'control']);
        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])->assertDontSee('Search artists');
    }

    public function test_the_editor_saves_triggers_and_an_alarm_and_loads_them_again(): void
    {
        $scene = $this->scene([$this->volume(20)]);
        $id = (string) $this->device->id;

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])
            ->call('addTrigger', 'mqtt')
            ->set('triggers.0.topic', 'home/doorbell/#')
            ->set('triggers.0.payload', 'ring')
            ->call('addTrigger', 'device')
            ->set('triggers.1.device_id', $id)
            ->set('triggers.1.value', 'standby')
            ->call('addTrigger', 'device')
            ->set('triggers.2.device_id', $id)
            ->set('triggers.2.event', 'source')
            ->assertSet('triggers.2.value', '')
            ->set('triggerDebounce', '45')
            ->set('alarmEnabled', true)
            ->set('alarmDeviceId', $id)
            ->set('alarmMinutes', '15')
            ->set('alarmStart', '4')
            ->set('alarmTarget', '35')
            ->call('save')
            ->assertHasNoErrors();

        $scene->refresh();
        $this->assertSame([
            ['type' => 'mqtt', 'topic' => 'home/doorbell/#', 'payload' => 'ring'],
            ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'state', 'value' => 'standby'],
            ['type' => 'device', 'device_id' => $this->device->id, 'event' => 'source'],
        ], $scene->triggers);
        $this->assertSame(45, $scene->trigger_debounce);
        $this->assertSame(['device_id' => $this->device->id, 'minutes' => 15, 'start_volume' => 4, 'target_volume' => 35], $scene->alarm);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])
            ->assertSet('triggers.0.topic', 'home/doorbell/#')
            ->assertSet('triggers.1.value', 'standby')
            ->assertSet('triggerDebounce', '45')
            ->assertSet('alarmEnabled', true)
            ->assertSet('alarmTarget', '35')
            ->call('removeTrigger', 0)
            ->set('alarmEnabled', false)
            ->call('save');

        $scene->refresh();
        $this->assertCount(2, $scene->triggers);
        $this->assertNull($scene->alarm);
    }

    public function test_the_editor_shows_what_is_wrong_with_a_trigger_or_the_alarm(): void
    {
        $scene = $this->scene([$this->volume(20)]);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])
            ->call('addTrigger', 'mqtt')
            ->set('triggers.0.topic', 'remoment/player/1/state')
            ->set('alarmEnabled', true)
            ->set('alarmDeviceId', (string) $this->device->id)
            ->set('alarmStart', '50')
            ->set('alarmTarget', '30')
            ->call('save')
            ->assertSee('server publishes on this topic itself')
            ->assertSee('target volume must be higher');

        $this->assertNull($scene->fresh()->triggers);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])
            ->call('addTrigger', 'mqtt')
            ->set('triggers.0.topic', 'a/b')
            ->set('triggerDebounce', '1')
            ->call('save')
            ->assertHasErrors('triggerDebounce');
    }

    public function test_the_list_marks_scenes_with_triggers_and_an_alarm(): void
    {
        $this->scene([$this->volume(20)], [
            'triggers' => [['type' => 'mqtt', 'topic' => 'a']],
            'alarm' => ['device_id' => $this->device->id, 'minutes' => 10, 'start_volume' => 1, 'target_volume' => 20],
        ]);

        Livewire::test(SceneList::class)->assertSee('1 trigger')->assertSee('Alarm');
    }

    public function test_the_sleep_timer_card_starts_and_cancels_a_timer(): void
    {
        Queue::fake();
        FakePlayerDriver::$volume = 30;

        $this->get('/scenes')->assertOk()->assertSee('Sleep timer');

        // Livewire's harness switches route-model binding off, so the card's REST calls go through a bridge that
        // answers like the API (the endpoints themselves are tested in DeviceTimersTest).
        $bridge = new class extends ApiBridge
        {
            public array $calls = [];

            public function __construct()
            {
                parent::__construct(app('router'));
            }

            public function call(string $method, string $path, array $params = []): array
            {
                $this->calls[] = [$method, $path, $params];

                return $method === 'POST' && $params['minutes'] > 480
                    ? [422, ['message' => 'The minutes field must not be greater than 480.', 'errors' => ['minutes' => ['Too long.']]]]
                    : [$method === 'POST' ? 201 : 200, ['status' => 'ok']];
            }
        };
        $this->app->instance(ApiBridge::class, $bridge);

        Livewire::test(\App\Livewire\SleepTimerCard::class)
            ->call('start')
            ->assertSee('Choose a device')
            ->set('deviceId', (string) $this->device->id)
            ->set('minutes', '45')
            ->set('fadeSeconds', '90')
            ->set('end', 'standby')
            ->call('start')
            ->assertSet('feedbackIsError', false)
            ->assertSee('Sleep timer set on Kitchen')
            ->set('minutes', '999')
            ->call('start')
            ->assertSet('feedbackIsError', true)
            ->assertSee('Too long.')
            ->call('cancel', $this->device->id)
            ->assertSee('cancelled');

        $this->assertSame(['POST', "devices/{$this->device->id}/sleep", ['minutes' => 45, 'fade_seconds' => 90, 'action' => 'standby']], $bridge->calls[0]);
        $this->assertSame(['DELETE', "devices/{$this->device->id}/sleep", []], end($bridge->calls));
    }

    public function test_the_sleep_timer_card_lists_a_running_timer(): void
    {
        Queue::fake();
        app(\App\Domain\Scenes\DeviceTimers::class)->startSleep($this->device, 30, 60);

        Livewire::test(\App\Livewire\SleepTimerCard::class)->assertSee('Kitchen')->assertSee('Stop in 30 min');
    }
}
